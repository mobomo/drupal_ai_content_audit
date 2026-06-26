<?php

declare(strict_types=1);

namespace Drupal\ai_site_audit\Controller;

use Drupal\ai_site_audit\Service\AnalysisOrchestrator;
use Drupal\ai_site_audit\Service\SiteAggregationService;
use Drupal\ai_site_audit\Service\SiteAnalysisService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the Sitewide AI Audit Dashboard.
 */
class SiteAuditDashboardController extends ControllerBase {

  public function __construct(
    protected AnalysisOrchestrator $orchestrator,
    protected SiteAggregationService $aggregationService,
    protected SiteAnalysisService $analysisService,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_site_audit.orchestrator'),
      $container->get('ai_site_audit.aggregation'),
      $container->get('ai_site_audit.analysis'),
      $container->get('config.factory'),
    );
  }

  /**
   * Main dashboard page.
   *
   * Assembles all cached tier data into a render array using the
   * ai_site_audit_dashboard theme hook.
   */
  public function dashboard(): array {
    $data = $this->orchestrator->getDashboardData();
    $config = $this->configFactory->get('ai_site_audit.settings');
    $canRunAnalysis = $this->analysisService->canRunAnalysis();

    $build = [
      '#theme' => 'ai_site_audit_dashboard',
      '#stats' => $data['stats'] ?? [],
      '#score_distribution' => $data['score_distribution'] ?? [],
      '#content_type_breakdown' => $data['content_type_breakdown'] ?? [],
      '#coverage' => $data['coverage'] ?? [],
      '#top_nodes' => $data['top_nodes'] ?? [],
      '#bottom_nodes' => $data['bottom_nodes'] ?? [],
      '#rollup' => $data['rollup'],
      '#ai_insights' => $data['ai_insights'],
      '#technical_audit' => $data['technical_audit'] ?? [],
      '#filesystem_audit' => $data['filesystem_audit'] ?? [],
      '#analysis_state' => $data['analysis_state'] ?? [],
      '#config' => [
        'enable_csv_export' => (bool) $config->get('enable_csv_export'),
        'enable_json_export' => (bool) $config->get('enable_json_export'),
        'can_run_analysis' => $canRunAnalysis['allowed'] ?? FALSE,
        'analysis_reason' => $canRunAnalysis['reason'] ?? '',
        'recomputation_needed' => $data['recomputation_needed'] ?? 'none',
        'new_assessment_count' => $data['new_assessment_count'] ?? 0,
      ],
      '#attached' => [
        'library' => ['ai_site_audit/site-dashboard'],
        'drupalSettings' => [
          'aiSiteAudit' => [
            'refreshStatsUrl' => Url::fromRoute('ai_site_audit.refresh_stats')->toString(),
            'aiInsightsUrl' => Url::fromRoute('ai_site_audit.ai_insights')->toString(),
            'triggerAnalysisUrl' => Url::fromRoute('ai_site_audit.trigger_analysis')->toString(),
            'analysisStatusUrl' => Url::fromRoute('ai_site_audit.analysis_status')->toString(),
            'exportCsvUrl' => Url::fromRoute('ai_site_audit.export', ['format' => 'csv'])->toString(),
            'exportJsonUrl' => Url::fromRoute('ai_site_audit.export', ['format' => 'json'])->toString(),
            'scoreDistribution' => $data['score_distribution'] ?? [],
            'scoreTrend' => $data['score_trend'] ?? [],
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['ai_content_assessment_list', 'ai_site_audit:summary'],
        'max-age' => (int) ($config->get('dashboard_cache_max_age') ?: 300),
        'contexts' => ['user.permissions'],
      ],
    ];

    return $build;
  }

  /**
   * AJAX endpoint: refresh Tier 1 SQL statistics.
   */
  public function refreshStats(): JsonResponse {
    $this->aggregationService->invalidateCache();

    $stats = $this->aggregationService->getOverallStats();
    $distribution = $this->aggregationService->getScoreDistribution();
    $coverage = $this->aggregationService->getCoverageStats();
    $contentTypes = $this->aggregationService->getContentTypeBreakdown();

    return new JsonResponse([
      'success' => TRUE,
      'data' => [
        'stats' => $stats,
        'score_distribution' => $distribution,
        'coverage' => $coverage,
        'content_type_breakdown' => $contentTypes,
      ],
    ]);
  }

  /**
   * AJAX endpoint: get cached AI insights or indicate none available.
   */
  public function aiInsights(): JsonResponse {
    $insights = $this->analysisService->getCachedInterpretation();
    $canRun = $this->analysisService->canRunAnalysis();

    return new JsonResponse([
      'success' => TRUE,
      'data' => [
        'insights' => $insights,
        'can_run_analysis' => $canRun['allowed'] ?? FALSE,
        'reason' => $canRun['reason'] ?? '',
      ],
    ]);
  }

  /**
   * AJAX endpoint: trigger a full analysis pipeline asynchronously.
   */
  public function triggerAnalysis(): JsonResponse {
    $config = $this->configFactory->get('ai_site_audit.settings');
    $tier = $config->get('analysis_tier_default') ?: 'tier_2';

    $enqueued = $this->orchestrator->enqueueAnalysis($tier);

    if ($enqueued) {
      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Analysis has been enqueued and will run shortly.',
        'tier' => $tier,
      ]);
    }

    return new JsonResponse([
      'success' => FALSE,
      'message' => 'Analysis is already in progress. Please wait for it to complete.',
    ], 409);
  }

  /**
   * AJAX endpoint: get current analysis progress state.
   */
  public function analysisStatus(): JsonResponse {
    $state = $this->orchestrator->getAnalysisState();

    return new JsonResponse([
      'success' => TRUE,
      'data' => $state,
    ]);
  }

  /**
   * Content type comparison page.
   *
   * Shows side-by-side audit metrics for each content type with score
   * distribution, sub-score breakdowns, and top action items per type.
   */
  public function contentTypeComparison(): array {
    $contentTypes = $this->aggregationService->getContentTypeBreakdown();
    $config = $this->configFactory->get('ai_site_audit.settings');

    // Get per-type score distributions and top/bottom nodes.
    $typeDetails = [];
    foreach ($contentTypes as $ct) {
      $type = $ct['type'];
      $topNodes = $this->aggregationService->getTopBottomNodes(5, 'best');
      $bottomNodes = $this->aggregationService->getTopBottomNodes(5, 'worst');

      // Filter to this content type.
      $typeTopNodes = array_values(array_filter($topNodes, fn($n) => $n['type'] === $type));
      $typeBottomNodes = array_values(array_filter($bottomNodes, fn($n) => $n['type'] === $type));

      $typeDetails[] = [
        'type' => $type,
        'label' => $ct['label'],
        'count' => $ct['count'],
        'avg_score' => $ct['avg_score'],
        'min_score' => $ct['min_score'],
        'max_score' => $ct['max_score'],
        'score_range' => $ct['max_score'] - $ct['min_score'],
        'top_nodes' => array_slice($typeTopNodes, 0, 3),
        'bottom_nodes' => array_slice($typeBottomNodes, 0, 3),
      ];
    }

    // Sort by avg_score ascending (worst first) for priority view.
    usort($typeDetails, fn($a, $b) => $a['avg_score'] <=> $b['avg_score']);

    // Get overall average for comparison baseline.
    $overallStats = $this->aggregationService->getOverallStats();

    $build = [
      '#theme' => 'ai_site_audit_content_type_comparison',
      '#content_types' => $typeDetails,
      '#overall_avg' => $overallStats['avg_score'] ?? 0,
      '#overall_count' => $overallStats['total_assessed'] ?? 0,
      '#attached' => [
        'library' => ['ai_site_audit/site-dashboard'],
      ],
      '#cache' => [
        'tags' => ['ai_content_assessment_list', 'ai_site_audit:summary'],
        'max-age' => (int) ($config->get('dashboard_cache_max_age') ?: 300),
        'contexts' => ['user.permissions'],
      ],
    ];

    return $build;
  }

  /**
   * Export dashboard data as CSV or JSON.
   *
   * @param string $format
   *   Export format: 'csv' or 'json'.
   */
  public function exportReport(string $format): Response {
    $config = $this->configFactory->get('ai_site_audit.settings');

    // Verify export is enabled.
    if ($format === 'csv' && !$config->get('enable_csv_export')) {
      return new JsonResponse(['error' => 'CSV export is disabled.'], 403);
    }
    if ($format === 'json' && !$config->get('enable_json_export')) {
      return new JsonResponse(['error' => 'JSON export is disabled.'], 403);
    }

    $data = $this->orchestrator->getDashboardData();

    if ($format === 'json') {
      return $this->exportJson($data);
    }

    return $this->exportCsv($data);
  }

  /**
   * Generate JSON export response.
   */
  protected function exportJson(array $data): Response {
    $export = [
      'generated_at' => date('c'),
      'stats' => $data['stats'] ?? [],
      'score_distribution' => $data['score_distribution'] ?? [],
      'coverage' => $data['coverage'] ?? [],
      'content_type_breakdown' => $data['content_type_breakdown'] ?? [],
      'top_nodes' => $data['top_nodes'] ?? [],
      'bottom_nodes' => $data['bottom_nodes'] ?? [],
      'rollup' => $data['rollup'],
      'ai_insights' => $data['ai_insights'],
      'technical_audit' => $data['technical_audit'] ?? [],
      'filesystem_audit' => $data['filesystem_audit'] ?? [],
    ];

    $response = new Response(
      json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
      200,
      [
        'Content-Type' => 'application/json',
        'Content-Disposition' => 'attachment; filename="site-audit-report-' . date('Y-m-d') . '.json"',
      ]
    );

    return $response;
  }

  /**
   * Generate CSV export response.
   */
  protected function exportCsv(array $data): Response {
    $output = fopen('php://temp', 'r+');

    // Overall stats section.
    fputcsv($output, ['Site Audit Report', date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, ['Overall Statistics']);
    fputcsv($output, ['Metric', 'Value']);

    $stats = $data['stats'] ?? [];
    fputcsv($output, ['Average Score', $stats['avg_score'] ?? 'N/A']);
    fputcsv($output, ['Median Score', $stats['median_score'] ?? 'N/A']);
    fputcsv($output, ['Total Assessed', $stats['total_assessed'] ?? 0]);
    fputcsv($output, ['AI Ready (80+)', $stats['ai_ready'] ?? 0]);
    fputcsv($output, ['Improving (50-79)', $stats['improving'] ?? 0]);
    fputcsv($output, ['Needs Work (<50)', $stats['needs_work'] ?? 0]);

    // Coverage.
    fputcsv($output, []);
    $coverage = $data['coverage'] ?? [];
    fputcsv($output, ['Coverage']);
    fputcsv($output, ['Total Published', $coverage['total_published'] ?? 0]);
    fputcsv($output, ['Total Assessed', $coverage['total_assessed'] ?? 0]);
    fputcsv($output, ['Coverage %', $coverage['coverage_pct'] ?? 0]);

    // Score distribution.
    fputcsv($output, []);
    fputcsv($output, ['Score Distribution']);
    fputcsv($output, ['Range', 'Count']);
    foreach ($data['score_distribution'] ?? [] as $range => $count) {
      fputcsv($output, [$range, $count]);
    }

    // Content type breakdown.
    fputcsv($output, []);
    fputcsv($output, ['Content Type Breakdown']);
    fputcsv($output, ['Type', 'Count', 'Avg Score', 'Min Score', 'Max Score']);
    foreach ($data['content_type_breakdown'] ?? [] as $ct) {
      fputcsv($output, [
        $ct['label'] ?? $ct['type'] ?? '',
        $ct['count'] ?? 0,
        $ct['avg_score'] ?? 0,
        $ct['min_score'] ?? 0,
        $ct['max_score'] ?? 0,
      ]);
    }

    // Bottom nodes.
    fputcsv($output, []);
    fputcsv($output, ['Bottom 10 Nodes (Worst Scores)']);
    fputcsv($output, ['NID', 'Title', 'Score', 'Type']);
    foreach ($data['bottom_nodes'] ?? [] as $node) {
      fputcsv($output, [$node['nid'], $node['title'], $node['score'], $node['type']]);
    }

    // Top nodes.
    fputcsv($output, []);
    fputcsv($output, ['Top 10 Nodes (Best Scores)']);
    fputcsv($output, ['NID', 'Title', 'Score', 'Type']);
    foreach ($data['top_nodes'] ?? [] as $node) {
      fputcsv($output, [$node['nid'], $node['title'], $node['score'], $node['type']]);
    }

    // Sub-score averages from rollup.
    $rollup = $data['rollup'] ?? [];
    if (!empty($rollup['sub_score_averages'])) {
      fputcsv($output, []);
      fputcsv($output, ['Sub-Score Averages']);
      fputcsv($output, ['Dimension', 'Average', 'Max Possible', 'Percentage']);
      foreach ($rollup['sub_score_averages'] as $dim => $d) {
        fputcsv($output, [$d['label'] ?? $dim, $d['avg'], $d['max_possible'], $d['pct'] . '%']);
      }
    }

    // Top failing checkpoints from rollup.
    if (!empty($rollup['top_failing_checkpoints'])) {
      fputcsv($output, []);
      fputcsv($output, ['Top Failing Checkpoints']);
      fputcsv($output, ['Checkpoint', 'Category', 'Failures', 'Failure %', 'Priority']);
      foreach ($rollup['top_failing_checkpoints'] as $cp) {
        fputcsv($output, [$cp['item'], $cp['category'], $cp['fail_count'], $cp['pct'] . '%', $cp['priority']]);
      }
    }

    // Top action items from rollup.
    if (!empty($rollup['top_action_items'])) {
      fputcsv($output, []);
      fputcsv($output, ['Most Common Action Items']);
      fputcsv($output, ['Action Item', 'Count', 'Priority']);
      foreach ($rollup['top_action_items'] as $ai) {
        fputcsv($output, [$ai['title'], $ai['count'], $ai['priority']]);
      }
    }

    // Filesystem audit results.
    $fsResults = $data['filesystem_audit'] ?? [];
    if (!empty($fsResults)) {
      fputcsv($output, []);
      fputcsv($output, ['Filesystem Audit Results']);
      fputcsv($output, ['Check', 'Status', 'Description']);
      foreach ($fsResults as $check) {
        fputcsv($output, [
          $check['label'] ?? $check['check'] ?? '',
          $check['status'] ?? '',
          $check['description'] ?? '',
        ]);
      }
    }

    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);

    return new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="site-audit-report-' . date('Y-m-d') . '.csv"',
    ]);
  }

}
