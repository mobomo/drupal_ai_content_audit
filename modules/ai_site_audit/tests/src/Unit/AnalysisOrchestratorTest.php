<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_site_audit\Unit;

use Drupal\ai_content_audit\Service\TechnicalAuditService;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\ai_site_audit\Service\AnalysisOrchestrator;
use Drupal\ai_site_audit\Service\SiteAggregationService;
use Drupal\ai_site_audit\Service\SiteAnalysisService;
use Drupal\ai_site_audit\Service\SiteRollupService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the AnalysisOrchestrator service.
 *
 * @coversDefaultClass \Drupal\ai_site_audit\Service\AnalysisOrchestrator
 * @group ai_site_audit
 */
class AnalysisOrchestratorTest extends TestCase {

  protected SiteAggregationService $aggregation;
  protected SiteRollupService $rollup;
  protected SiteAnalysisService $analysis;
  protected TechnicalAuditService $technicalAudit;
  protected StateInterface $state;
  protected QueueFactory $queueFactory;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->aggregation = $this->createMock(SiteAggregationService::class);
    $this->rollup = $this->createMock(SiteRollupService::class);
    $this->analysis = $this->createMock(SiteAnalysisService::class);
    $this->technicalAudit = $this->createMock(TechnicalAuditService::class);
    $this->state = $this->createMock(StateInterface::class);
    $this->queueFactory = $this->createMock(QueueFactory::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    // Default config mock.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['analysis_tier_default', 'tier_2'],
        ['auto_analysis_threshold', 0.10],
      ]);
    $this->configFactory->method('get')
      ->with('ai_site_audit.settings')
      ->willReturn($config);
  }

  /**
   * Create an orchestrator instance with all mocked dependencies.
   */
  protected function createOrchestrator(): AnalysisOrchestrator {
    return new AnalysisOrchestrator(
      $this->aggregation,
      $this->rollup,
      $this->analysis,
      $this->technicalAudit,
      $this->state,
      $this->queueFactory,
      $this->configFactory,
      $this->logger,
    );
  }

  /**
   * @covers ::runAnalysis
   */
  public function testRunAnalysisTier1OnlyCallsAggregation(): void {
    $this->aggregation->expects($this->once())->method('getOverallStats')->willReturn(['avg_score' => 65.5]);
    $this->aggregation->expects($this->once())->method('getScoreDistribution')->willReturn([]);
    $this->aggregation->expects($this->once())->method('getContentTypeBreakdown')->willReturn([]);
    $this->aggregation->expects($this->once())->method('getCoverageStats')->willReturn([]);
    $this->aggregation->expects($this->exactly(2))->method('getTopBottomNodes')->willReturn([]);

    // Tier 1 should NOT call rollup or analysis.
    $this->rollup->expects($this->never())->method('computeRollup');
    $this->analysis->expects($this->never())->method('analyzeStatistics');

    // State tracking.
    $this->state->expects($this->atLeast(2))->method('set');

    $orchestrator = $this->createOrchestrator();
    $result = $orchestrator->runAnalysis('tier_1');

    $this->assertArrayHasKey('stats', $result);
    $this->assertArrayHasKey('completed_at', $result);
    $this->assertEquals('tier_1', $result['tier']);
  }

  /**
   * @covers ::runAnalysis
   */
  public function testRunAnalysisTier2CallsRollup(): void {
    $this->aggregation->method('getOverallStats')->willReturn(['avg_score' => 70]);
    $this->aggregation->method('getScoreDistribution')->willReturn([]);
    $this->aggregation->method('getContentTypeBreakdown')->willReturn([]);
    $this->aggregation->method('getCoverageStats')->willReturn([]);
    $this->aggregation->method('getTopBottomNodes')->willReturn([]);

    $this->rollup->expects($this->once())->method('computeRollup')->willReturn([
      'total_assessed' => 100,
      'avg_score' => 70,
    ]);

    // Tier 2 should NOT call AI analysis.
    $this->analysis->expects($this->never())->method('analyzeStatistics');

    $this->state->method('set');

    $orchestrator = $this->createOrchestrator();
    $result = $orchestrator->runAnalysis('tier_2');

    $this->assertArrayHasKey('rollup', $result);
    $this->assertEquals(100, $result['rollup']['total_assessed']);
  }

  /**
   * @covers ::runAnalysis
   */
  public function testRunAnalysisTier3CallsAiAnalysis(): void {
    $this->aggregation->method('getOverallStats')->willReturn([]);
    $this->aggregation->method('getScoreDistribution')->willReturn([]);
    $this->aggregation->method('getContentTypeBreakdown')->willReturn([]);
    $this->aggregation->method('getCoverageStats')->willReturn([]);
    $this->aggregation->method('getTopBottomNodes')->willReturn([]);

    $this->rollup->expects($this->once())->method('computeRollup')->willReturn(['avg_score' => 55]);

    $techResult = new TechnicalAuditResult(
      check: 'robots_txt',
      label: 'robots.txt',
      status: 'pass',
      currentContent: 'User-agent: *',
      recommendedContent: NULL,
      description: 'robots.txt found',
    );
    $this->technicalAudit->expects($this->once())->method('runAllChecks')->willReturn([$techResult]);

    $this->analysis->expects($this->once())->method('analyzeStatistics')->willReturn([
      'overall_grade' => 'C',
      'executive_summary' => 'Test summary',
    ]);

    $this->state->method('set');

    $orchestrator = $this->createOrchestrator();
    $result = $orchestrator->runAnalysis('tier_3');

    $this->assertArrayHasKey('ai_insights', $result);
    $this->assertEquals('C', $result['ai_insights']['overall_grade']);
    $this->assertArrayHasKey('technical_audit', $result);
  }

  /**
   * @covers ::runAnalysis
   */
  public function testRunAnalysisHandlesException(): void {
    $this->aggregation->method('getOverallStats')->willThrowException(new \RuntimeException('DB error'));
    $this->state->method('set');
    $this->logger->expects($this->once())->method('error');

    $orchestrator = $this->createOrchestrator();
    $result = $orchestrator->runAnalysis('tier_1');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('DB error', $result['error']);
  }

  /**
   * @covers ::enqueueAnalysis
   */
  public function testEnqueueAnalysisWhenIdle(): void {
    $this->state->method('get')
      ->willReturnMap([
        ['ai_site_audit.analysis_state', 'idle', 'idle'],
      ]);

    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())->method('createItem');
    $this->queueFactory->method('get')
      ->with('ai_site_audit_analysis')
      ->willReturn($queue);

    $orchestrator = $this->createOrchestrator();
    $result = $orchestrator->enqueueAnalysis('tier_2');

    $this->assertTrue($result);
  }

  /**
   * @covers ::enqueueAnalysis
   */
  public function testEnqueueAnalysisBlockedWhenRunning(): void {
    $this->state->method('get')
      ->willReturnMap([
        ['ai_site_audit.analysis_state', 'idle', 'rolling_up'],
      ]);

    $this->queueFactory->expects($this->never())->method('get');

    $orchestrator = $this->createOrchestrator();
    $result = $orchestrator->enqueueAnalysis('tier_2');

    $this->assertFalse($result);
  }

  /**
   * @covers ::getDashboardData
   */
  public function testGetDashboardDataAssemblesAllTiers(): void {
    $this->aggregation->method('getOverallStats')->willReturn(['avg_score' => 72]);
    $this->aggregation->method('getScoreDistribution')->willReturn(['80-100' => 50]);
    $this->aggregation->method('getContentTypeBreakdown')->willReturn([]);
    $this->aggregation->method('getCoverageStats')->willReturn(['coverage_pct' => 85.0]);
    $this->aggregation->method('getTopBottomNodes')->willReturn([]);
    $this->aggregation->method('getScoreTrend')->willReturn([]);

    $this->rollup->method('getCachedRollup')->willReturn(['avg_score' => 72]);
    $this->rollup->method('getRecomputationNeeded')->willReturn('none');
    $this->rollup->method('getNewAssessmentCount')->willReturn(0);

    $this->analysis->method('getCachedInterpretation')->willReturn(['overall_grade' => 'B']);

    $this->state->method('get')->willReturn('idle');

    $this->technicalAudit->method('runAllChecks')->willReturn([]);

    $orchestrator = $this->createOrchestrator();
    $data = $orchestrator->getDashboardData();

    $this->assertArrayHasKey('stats', $data);
    $this->assertArrayHasKey('rollup', $data);
    $this->assertArrayHasKey('ai_insights', $data);
    $this->assertArrayHasKey('technical_audit', $data);
    $this->assertArrayHasKey('analysis_state', $data);
    $this->assertEquals(72, $data['stats']['avg_score']);
  }

  /**
   * @covers ::shouldReanalyze
   */
  public function testShouldReanalyzeWhenNoRollup(): void {
    $this->rollup->method('getCachedRollup')->willReturn(NULL);

    $orchestrator = $this->createOrchestrator();
    $this->assertTrue($orchestrator->shouldReanalyze());
  }

  /**
   * @covers ::shouldReanalyze
   */
  public function testShouldReanalyzeExceedsThreshold(): void {
    $this->rollup->method('getCachedRollup')->willReturn(['total_assessed' => 100]);
    $this->rollup->method('getNewAssessmentCount')->willReturn(15); // 15% > 10%

    $orchestrator = $this->createOrchestrator();
    $this->assertTrue($orchestrator->shouldReanalyze());
  }

  /**
   * @covers ::shouldReanalyze
   */
  public function testShouldNotReanalyzeBelowThreshold(): void {
    $this->rollup->method('getCachedRollup')->willReturn(['total_assessed' => 100]);
    $this->rollup->method('getNewAssessmentCount')->willReturn(5); // 5% < 10%

    $orchestrator = $this->createOrchestrator();
    $this->assertFalse($orchestrator->shouldReanalyze());
  }

}
