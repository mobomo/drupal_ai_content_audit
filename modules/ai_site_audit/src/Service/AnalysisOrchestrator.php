<?php

declare(strict_types=1);

namespace Drupal\ai_site_audit\Service;

use Drupal\ai_content_audit_scoring\Service\FilesystemAuditService;
use Drupal\ai_content_audit_scoring\Service\TechnicalAuditService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the multi-tier sitewide analysis pipeline.
 *
 * Coordinates: Tier 1 SQL → Tier 2 PHP Rollup → Tier 3 AI Interpretation.
 * Manages the analysis state lifecycle:
 * idle → aggregating → rolling_up → ai_interpreting → complete.
 */
class AnalysisOrchestrator {

  /**
   * Analysis state constants.
   */
  protected const STATE_IDLE = 'idle';
  protected const STATE_AGGREGATING = 'aggregating';
  protected const STATE_ROLLING_UP = 'rolling_up';
  protected const STATE_AI_INTERPRETING = 'ai_interpreting';
  protected const STATE_COMPLETE = 'complete';
  protected const STATE_FAILED = 'failed';

  public function __construct(
    protected SiteAggregationService $aggregationService,
    protected SiteRollupService $rollupService,
    protected SiteAnalysisService $analysisService,
    protected TechnicalAuditService $technicalAuditService,
    protected FilesystemAuditService $filesystemAuditService,
    protected StateInterface $state,
    protected QueueFactory $queueFactory,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Run the analysis pipeline synchronously.
   *
   * @param string $tier
   *   Maximum tier to execute: 'tier_1', 'tier_2', or 'tier_3'.
   *
   * @return array
   *   The analysis results including stats, rollup, and optionally AI insights.
   */
  public function runAnalysis(string $tier = 'tier_2'): array {
    $result = [
      'started_at' => time(),
      'tier' => $tier,
    ];

    try {
      // Tier 1: SQL aggregation (always runs).
      $this->setAnalysisState(self::STATE_AGGREGATING);
      $result['stats'] = $this->aggregationService->getOverallStats();
      $result['score_distribution'] = $this->aggregationService->getScoreDistribution();
      $result['content_type_breakdown'] = $this->aggregationService->getContentTypeBreakdown();
      $result['coverage'] = $this->aggregationService->getCoverageStats();
      $result['top_nodes'] = $this->aggregationService->getTopBottomNodes(10, 'best');
      $result['bottom_nodes'] = $this->aggregationService->getTopBottomNodes(10, 'worst');

      if ($tier === 'tier_1') {
        $this->setAnalysisState(self::STATE_COMPLETE);
        $result['completed_at'] = time();
        return $result;
      }

      // Tier 2: PHP rollup.
      $this->setAnalysisState(self::STATE_ROLLING_UP);
      $result['rollup'] = $this->rollupService->computeRollup();

      if ($tier === 'tier_2') {
        $this->setAnalysisState(self::STATE_COMPLETE);
        $result['completed_at'] = time();
        return $result;
      }

      // Tier 3: AI interpretation.
      $this->setAnalysisState(self::STATE_AI_INTERPRETING);

      // Get technical audit results to include.
      $technicalResults = $this->technicalAuditService->runAllChecks();
      // Convert TechnicalAuditResult objects to arrays.
      $techArrays = array_map(
        fn($r) => $r->toArray(),
        $technicalResults
      );

      // Get filesystem audit results.
      $filesystemResults = $this->filesystemAuditService->runAllChecks();
      $fsArrays = array_map(
        fn($r) => $r->toArray(),
        $filesystemResults
      );

      $result['ai_insights'] = $this->analysisService->analyzeStatistics(
        $result['rollup'],
        $techArrays,
        $fsArrays,
      );
      $result['technical_audit'] = $techArrays;
      $result['filesystem_audit'] = $fsArrays;

      // Fix B: analyzeStatistics() catches its own exceptions and returns an
      // error array rather than re-throwing. Treat that as a pipeline failure
      // so the state is set to STATE_FAILED instead of falling through to
      // STATE_COMPLETE, which would make the UI reload with no insights.
      if (isset($result['ai_insights']['error'])) {
        $errorMessage = $result['ai_insights']['error'];
        $this->setAnalysisState(self::STATE_FAILED);
        $this->state->set('ai_site_audit.analysis_progress', [
          'state' => self::STATE_FAILED,
          'error' => $errorMessage,
          'failed_at' => time(),
        ]);
        $this->logger->error('AI interpretation step failed (non-exception): @error', [
          '@error' => $errorMessage,
        ]);
        $result['error'] = $errorMessage;
        $result['completed_at'] = time();
        return $result;
      }

      $this->setAnalysisState(self::STATE_COMPLETE);
      $result['completed_at'] = time();

      return $result;
    }
    catch (\Exception $e) {
      $this->setAnalysisState(self::STATE_FAILED);
      $this->state->set('ai_site_audit.analysis_progress', [
        'error' => $e->getMessage(),
        'failed_at' => time(),
      ]);
      $this->logger->error('Analysis pipeline failed: @message', ['@message' => $e->getMessage()]);

      $result['error'] = $e->getMessage();
      $result['completed_at'] = time();
      return $result;
    }
  }

  /**
   * Enqueue an analysis to be run asynchronously via the queue.
   *
   * @param string $tier
   *   Maximum tier: 'tier_1', 'tier_2', or 'tier_3'.
   *
   * @return bool
   *   TRUE if the analysis was enqueued, FALSE if already running.
   */
  public function enqueueAnalysis(string $tier = 'tier_2'): bool {
    $currentState = $this->state->get('ai_site_audit.analysis_state', self::STATE_IDLE);

    // Don't enqueue if already running.
    if (!in_array($currentState, [self::STATE_IDLE, self::STATE_COMPLETE, self::STATE_FAILED], TRUE)) {
      $this->logger->info('Analysis already in progress (state: @state). Skipping enqueue.', ['@state' => $currentState]);
      return FALSE;
    }

    $queue = $this->queueFactory->get('ai_site_audit_analysis');
    $queue->createItem([
      'tier' => $tier,
      'enqueued_at' => time(),
    ]);

    // Fix A: Immediately advance the state to STATE_AGGREGATING after enqueue.
    // This prevents the JS poller from seeing a stale 'complete' state left
    // over from a previous (possibly failed) run and triggering a phantom page
    // reload before the queue worker has had a chance to execute.
    // Setting a non-terminal state also prevents a second click from
    // double-enqueueing while the first item is still in the queue.
    $this->setAnalysisState(self::STATE_AGGREGATING);

    $this->logger->info('Sitewide analysis enqueued at tier @tier.', ['@tier' => $tier]);
    return TRUE;
  }

  /**
   * Get the current analysis state and progress metadata.
   *
   * @return array
   *   Array with 'state', 'progress', and 'last_completed' keys.
   */
  public function getAnalysisState(): array {
    return [
      'state' => $this->state->get('ai_site_audit.analysis_state', self::STATE_IDLE),
      'progress' => $this->state->get('ai_site_audit.analysis_progress', []),
      'last_rollup_time' => $this->state->get('ai_site_audit.last_rollup_time', 0),
      'last_ai_analysis_time' => $this->state->get('ai_site_audit.last_ai_analysis_time', 0),
    ];
  }

  /**
   * Get all dashboard data, combining cached results from all tiers.
   *
   * This is the primary method called by the dashboard controller.
   *
   * @return array
   *   Comprehensive dashboard data structure.
   */
  public function getDashboardData(): array {
    $data = [
      'stats' => $this->aggregationService->getOverallStats(),
      'score_distribution' => $this->aggregationService->getScoreDistribution(),
      'content_type_breakdown' => $this->aggregationService->getContentTypeBreakdown(),
      'coverage' => $this->aggregationService->getCoverageStats(),
      'top_nodes' => $this->aggregationService->getTopBottomNodes(10, 'best'),
      'bottom_nodes' => $this->aggregationService->getTopBottomNodes(10, 'worst'),
      'score_trend' => $this->aggregationService->getScoreTrend(),
      'rollup' => $this->rollupService->getCachedRollup(),
      'ai_insights' => $this->analysisService->getCachedInterpretation(),
      'analysis_state' => $this->getAnalysisState(),
      'recomputation_needed' => $this->rollupService->getRecomputationNeeded(),
      'new_assessment_count' => $this->rollupService->getNewAssessmentCount(),
    ];

    // Include technical audit if available.
    try {
      $techResults = $this->technicalAuditService->runAllChecks();
      $data['technical_audit'] = array_map(
        fn($r) => $r->toArray(),
        $techResults
      );
    }
    catch (\Exception $e) {
      $data['technical_audit'] = [];
      $this->logger->warning('Technical audit failed in dashboard: @message', ['@message' => $e->getMessage()]);
    }

    // Include filesystem audit if available.
    try {
      $fsResults = $this->filesystemAuditService->runAllChecks();
      $data['filesystem_audit'] = array_map(
        fn($r) => $r->toArray(),
        $fsResults
      );
    }
    catch (\Exception $e) {
      $data['filesystem_audit'] = [];
      $this->logger->warning('Filesystem audit failed in dashboard: @message', ['@message' => $e->getMessage()]);
    }

    return $data;
  }

  /**
   * Determine whether re-analysis should be triggered automatically.
   *
   * @return bool
   *   TRUE if the threshold for re-analysis has been exceeded.
   */
  public function shouldReanalyze(): bool {
    $config = $this->configFactory->get('ai_site_audit.settings');
    $threshold = (float) ($config->get('auto_analysis_threshold') ?: 0.10);

    $rollup = $this->rollupService->getCachedRollup();
    if ($rollup === NULL) {
      return TRUE;
    }

    $newCount = $this->rollupService->getNewAssessmentCount();
    $totalAssessed = $rollup['total_assessed'] ?? 0;

    if ($totalAssessed === 0) {
      return $newCount > 0;
    }

    return ($newCount / $totalAssessed) >= $threshold;
  }

  /**
   * Set the analysis state in the State API.
   */
  protected function setAnalysisState(string $state): void {
    $this->state->set('ai_site_audit.analysis_state', $state);
    $this->state->set('ai_site_audit.analysis_progress', [
      'state' => $state,
      'updated_at' => time(),
    ]);
  }

}
