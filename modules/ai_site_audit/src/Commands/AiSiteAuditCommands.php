<?php

declare(strict_types=1);

namespace Drupal\ai_site_audit\Commands;

use Drupal\ai_site_audit\Service\AnalysisOrchestrator;
use Drupal\ai_site_audit\Service\SiteAggregationService;
use Drupal\ai_site_audit\Service\SiteRollupService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drush\Attributes\Command;
use Drush\Attributes\Help;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drush commands for the AI Site Audit submodule.
 */
final class AiSiteAuditCommands extends DrushCommands {

  public function __construct(
    private readonly AnalysisOrchestrator $orchestrator,
    private readonly SiteAggregationService $aggregationService,
    private readonly SiteRollupService $rollupService,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Gets the console style helper.
   *
   * Provides a concrete return type for static analysis.
   *
   * @return \Symfony\Component\Console\Style\SymfonyStyle
   *   The console style helper.
   */
  private function style(): SymfonyStyle {
    return new SymfonyStyle($this->input(), $this->output());
  }

  /**
   * Run sitewide content audit analysis.
   */
  #[Command(name: 'ai_site_audit:analyze', aliases: ['asa'])]
  #[Help(description: 'Run sitewide AI content audit analysis pipeline.')]
  #[Option(name: 'tier', description: 'Analysis tier: tier_1 (SQL only), tier_2 (SQL + rollup), tier_3 (full with AI).')]
  #[Option(name: 'force', description: 'Force full recomputation, ignoring caches.')]
  #[Usage(name: 'drush ai_site_audit:analyze', description: 'Run analysis at the configured default tier.')]
  #[Usage(name: 'drush ai_site_audit:analyze --tier=tier_3', description: 'Run full analysis including AI interpretation.')]
  #[Usage(name: 'drush ai_site_audit:analyze --tier=tier_1 --force', description: 'Force refresh of SQL statistics only.')]
  public function analyze(array $options = ['tier' => self::OPT, 'force' => FALSE]): void {
    $config = $this->configFactory->get('ai_site_audit.settings');
    $tier = $options['tier'] ?? $config->get('analysis_tier_default') ?: 'tier_2';

    if ($options['force']) {
      $this->aggregationService->invalidateCache();
      $this->logger()->notice('Cache invalidated — forcing full recomputation.');
    }

    $this->logger()->notice(dt('Starting sitewide analysis at tier @tier...', ['@tier' => $tier]));

    $result = $this->orchestrator->runAnalysis($tier);

    if (isset($result['error'])) {
      $this->logger()->error(dt('Analysis failed: @error', ['@error' => $result['error']]));
      return;
    }

    // Display results summary.
    $stats = $result['stats'] ?? [];
    $this->style()->section('Overall Statistics');
    $this->style()->definitionList(
      ['Average Score' => $stats['avg_score'] ?? 'N/A'],
      ['Total Assessed' => $stats['total_assessed'] ?? 0],
      ['AI Ready (80+)' => $stats['ai_ready'] ?? 0],
      ['Improving (50-79)' => $stats['improving'] ?? 0],
      ['Needs Work (<50)' => $stats['needs_work'] ?? 0],
    );

    // Coverage.
    $coverage = $result['coverage'] ?? [];
    if (!empty($coverage)) {
      $this->style()->section('Coverage');
      $this->style()->definitionList(
        ['Published Nodes' => $coverage['total_published'] ?? 0],
        ['Assessed Nodes' => $coverage['total_assessed'] ?? 0],
        ['Coverage' => ($coverage['coverage_pct'] ?? 0) . '%'],
      );
    }

    // Score distribution.
    $dist = $result['score_distribution'] ?? [];
    if (!empty($dist)) {
      $this->style()->section('Score Distribution');
      $rows = [];
      foreach ($dist as $range => $count) {
        $rows[] = [$range, $count];
      }
      $this->style()->table(['Range', 'Count'], $rows);
    }

    // Rollup data if Tier 2+.
    if (isset($result['rollup']) && !empty($result['rollup']['sub_score_averages'])) {
      $this->style()->section('Sub-Score Averages');
      $rows = [];
      foreach ($result['rollup']['sub_score_averages'] as $dim => $data) {
        $rows[] = [$data['label'] ?? $dim, $data['avg'], $data['max_possible'], $data['pct'] . '%'];
      }
      $this->style()->table(['Dimension', 'Average', 'Max', 'Percentage'], $rows);
    }

    // AI insights if Tier 3.
    if (isset($result['ai_insights']) && !isset($result['ai_insights']['error'])) {
      $this->style()->section('AI Insights');
      $insights = $result['ai_insights'];
      if (!empty($insights['overall_grade'])) {
        $this->style()->text(dt('Overall Grade: @grade', ['@grade' => $insights['overall_grade']]));
      }
      if (!empty($insights['executive_summary'])) {
        $this->style()->text($insights['executive_summary']);
      }
      if (!empty($insights['quick_wins'])) {
        $this->style()->section('Quick Wins');
        foreach ($insights['quick_wins'] as $qw) {
          $this->style()->text('• ' . ($qw['title'] ?? '') . ': ' . ($qw['description'] ?? ''));
        }
      }
    }

    $duration = ($result['completed_at'] ?? time()) - ($result['started_at'] ?? time());
    $this->logger()->success(dt('Analysis completed in @duration seconds at tier @tier.', [
      '@duration' => $duration,
      '@tier' => $tier,
    ]));
  }

  /**
   * Display current sitewide statistics.
   */
  #[Command(name: 'ai_site_audit:stats', aliases: ['ass'])]
  #[Help(description: 'Display current sitewide content audit statistics from cache.')]
  #[Option(name: 'format', description: 'Output format: table (default) or json.')]
  #[Usage(name: 'drush ai_site_audit:stats', description: 'Show statistics in table format.')]
  #[Usage(name: 'drush ai_site_audit:stats --format=json', description: 'Show statistics as JSON.')]
  public function stats(array $options = ['format' => 'table']): void {
    $stats = $this->aggregationService->getOverallStats();
    $coverage = $this->aggregationService->getCoverageStats();
    $distribution = $this->aggregationService->getScoreDistribution();
    $contentTypes = $this->aggregationService->getContentTypeBreakdown();

    if ($options['format'] === 'json') {
      $this->output()->writeln(json_encode([
        'stats' => $stats,
        'coverage' => $coverage,
        'score_distribution' => $distribution,
        'content_types' => $contentTypes,
      ], JSON_PRETTY_PRINT));
      return;
    }

    // Table format.
    $this->style()->section('Overall Statistics');
    $this->style()->definitionList(
      ['Average Score' => $stats['avg_score'] ?? 'N/A'],
      ['Median Score' => $stats['median_score'] ?? 'N/A'],
      ['Total Assessed' => $stats['total_assessed'] ?? 0],
      ['AI Ready (80+)' => $stats['ai_ready'] ?? 0],
      ['Improving (50-79)' => $stats['improving'] ?? 0],
      ['Needs Work (<50)' => $stats['needs_work'] ?? 0],
      ['Min Score' => $stats['min_score'] ?? 'N/A'],
      ['Max Score' => $stats['max_score'] ?? 'N/A'],
    );

    $this->style()->section('Coverage');
    $this->style()->definitionList(
      ['Published Nodes' => $coverage['total_published'] ?? 0],
      ['Assessed Nodes' => $coverage['total_assessed'] ?? 0],
      ['Coverage' => ($coverage['coverage_pct'] ?? 0) . '%'],
      ['Unassessed' => $coverage['unassessed_count'] ?? 0],
    );

    $this->style()->section('Score Distribution');
    $rows = [];
    foreach ($distribution as $range => $count) {
      $rows[] = [$range, $count];
    }
    $this->style()->table(['Range', 'Count'], $rows);

    if (!empty($contentTypes)) {
      $this->style()->section('Content Type Breakdown');
      $rows = [];
      foreach ($contentTypes as $ct) {
        $rows[] = [$ct['label'], $ct['count'], $ct['avg_score'], $ct['min_score'], $ct['max_score']];
      }
      $this->style()->table(['Type', 'Count', 'Avg Score', 'Min', 'Max'], $rows);
    }

    // Show analysis state.
    $analysisState = $this->orchestrator->getAnalysisState();
    $this->style()->section('Analysis State');
    $this->style()->definitionList(
      ['Current State' => $analysisState['state'] ?? 'unknown'],
      ['Last Rollup' => $analysisState['last_rollup_time'] ? date('Y-m-d H:i:s', $analysisState['last_rollup_time']) : 'Never'],
      ['Last AI Analysis' => $analysisState['last_ai_analysis_time'] ? date('Y-m-d H:i:s', $analysisState['last_ai_analysis_time']) : 'Never'],
      ['New Assessments Since Rollup' => $this->rollupService->getNewAssessmentCount()],
    );
  }

  /**
   * Export sitewide audit report.
   */
  #[Command(name: 'ai_site_audit:report', aliases: ['asr'])]
  #[Help(description: 'Export sitewide audit report as JSON.')]
  #[Option(name: 'output', description: 'File path to write the report. Defaults to stdout.')]
  #[Usage(name: 'drush ai_site_audit:report', description: 'Print full report as JSON.')]
  #[Usage(name: 'drush ai_site_audit:report --output=/tmp/report.json', description: 'Write report to file.')]
  public function report(array $options = ['output' => self::OPT]): void {
    $data = $this->orchestrator->getDashboardData();

    $report = [
      'generated_at' => date('c'),
      'stats' => $data['stats'] ?? [],
      'coverage' => $data['coverage'] ?? [],
      'score_distribution' => $data['score_distribution'] ?? [],
      'content_type_breakdown' => $data['content_type_breakdown'] ?? [],
      'top_nodes' => $data['top_nodes'] ?? [],
      'bottom_nodes' => $data['bottom_nodes'] ?? [],
      'rollup' => $data['rollup'],
      'ai_insights' => $data['ai_insights'],
      'analysis_state' => $data['analysis_state'] ?? [],
      'technical_audit' => $data['technical_audit'] ?? [],
    ];

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if (!empty($options['output'])) {
      $path = $options['output'];
      if (file_put_contents($path, $json) !== FALSE) {
        $this->logger()->success(dt('Report written to @path', ['@path' => $path]));
      }
      else {
        $this->logger()->error(dt('Failed to write report to @path', ['@path' => $path]));
      }
      return;
    }

    $this->output()->writeln($json);
  }

}
