<?php

declare(strict_types=1);

namespace Drupal\ai_site_audit\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ai_site_audit\Service\AnalysisOrchestrator;
use Drupal\ai_site_audit\Service\SiteAggregationService;
use Psr\Log\LoggerInterface;

/**
 * Hook implementations for ai_site_audit.
 */
class AiSiteAuditHooks {

  use StringTranslationTrait;

  /**
   * Constructs a new AiSiteAuditHooks object.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly StateInterface $state,
    protected readonly KeyValueFactoryInterface $keyValueFactory,
    protected readonly KeyValueExpirableFactoryInterface $keyValueExpirableFactory,
    protected readonly AnalysisOrchestrator $analysisOrchestrator,
    protected readonly SiteAggregationService $siteAggregation,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'ai_site_audit_dashboard' => [
        'variables' => [
          'stats' => NULL,
          'score_distribution' => NULL,
          'content_type_breakdown' => NULL,
          'coverage' => NULL,
          'top_nodes' => NULL,
          'bottom_nodes' => NULL,
          'rollup' => NULL,
          'ai_insights' => NULL,
          'technical_audit' => NULL,
          'filesystem_audit' => NULL,
          'analysis_state' => NULL,
          'config' => NULL,
        ],
        'template' => 'ai-site-audit-dashboard',
      ],
      'ai_site_audit_stats_cards' => [
        'variables' => [
          'stats' => NULL,
          'coverage' => NULL,
        ],
        'template' => 'ai-site-audit-stats-cards',
      ],
      'ai_site_audit_score_distribution' => [
        'variables' => [
          'distribution' => NULL,
        ],
        'template' => 'ai-site-audit-score-distribution',
      ],
      'ai_site_audit_content_type_table' => [
        'variables' => [
          'content_types' => NULL,
        ],
        'template' => 'ai-site-audit-content-type-table',
      ],
      'ai_site_audit_ai_insights' => [
        'variables' => [
          'insights' => NULL,
          'analysis_state' => NULL,
          'can_run_analysis' => FALSE,
        ],
        'template' => 'ai-site-audit-ai-insights',
      ],
      'ai_site_audit_technical_summary' => [
        'variables' => [
          'results' => NULL,
        ],
        'template' => 'ai-site-audit-technical-summary',
      ],
      'ai_site_audit_content_type_comparison' => [
        'variables' => [
          'content_types' => NULL,
          'overall_avg' => NULL,
          'overall_count' => NULL,
        ],
        'template' => 'ai-site-audit-content-type-comparison',
      ],
    ];
  }

  /**
   * Implements hook_cron().
   *
   * Triggers periodic sitewide analysis based on configuration.
   * Takes trend snapshots if enabled.
   */
  #[Hook('cron')]
  public function cron(): void {
    $config = $this->configFactory->get('ai_site_audit.settings');

    // Cron-based analysis.
    if ($config->get('enable_cron_analysis')) {
      $last_analysis_time = (int) $this->state->get('ai_site_audit.last_ai_analysis_time', 0);
      $frequency_hours = (int) ($config->get('cron_analysis_frequency_hours') ?: 24);

      if ((time() - $last_analysis_time) >= ($frequency_hours * 3600)) {
        $current_state = $this->state->get('ai_site_audit.analysis_state', 'idle');
        if (in_array($current_state, ['idle', 'complete', 'failed'], TRUE)) {
          $tier = $config->get('analysis_tier_default') ?: 'tier_2';
          $this->analysisOrchestrator->enqueueAnalysis($tier);
          $this->logger->info('Cron-triggered sitewide analysis enqueued at tier @tier.', ['@tier' => $tier]);
        }
      }
    }

    // Trend snapshots.
    if ($config->get('enable_trend_snapshots')) {
      $last_snapshot_date = $this->state->get('ai_site_audit.last_snapshot_date', '');
      $today = date('Ymd');

      if ($last_snapshot_date !== $today) {
        try {
          $stats = $this->siteAggregation->getOverallStats();
          $coverage = $this->siteAggregation->getCoverageStats();
          $distribution = $this->siteAggregation->getScoreDistribution();

          $snapshot = [
            'date' => $today,
            'timestamp' => time(),
            'avg_score' => $stats['avg_score'] ?? NULL,
            'median_score' => $stats['median_score'] ?? NULL,
            'total_assessed' => $stats['total_assessed'] ?? 0,
            'ai_ready' => $stats['ai_ready'] ?? 0,
            'improving' => $stats['improving'] ?? 0,
            'needs_work' => $stats['needs_work'] ?? 0,
            'coverage_pct' => $coverage['coverage_pct'] ?? 0,
            'score_distribution' => $distribution,
          ];

          $kv_trends = $this->keyValueFactory->get('ai_site_audit.trends');
          $kv_trends->set('snapshot_' . $today, $snapshot);
          $this->state->set('ai_site_audit.last_snapshot_date', $today);

          // Prune old snapshots beyond retention.
          $retention_days = (int) ($config->get('trend_snapshot_retention_days') ?: 90);
          $cutoff_date = date('Ymd', strtotime("-{$retention_days} days"));
          $all_keys = $kv_trends->getAll();
          foreach ($all_keys as $key => $value) {
            if (str_starts_with($key, 'snapshot_')) {
              $snapshot_date = substr($key, 9);
              if ($snapshot_date < $cutoff_date) {
                $kv_trends->delete($key);
              }
            }
          }

          $this->logger->info('Daily trend snapshot recorded for @date.', ['@date' => $today]);
        }
        catch (\Exception $e) {
          $this->logger->error('Failed to record trend snapshot: @message', ['@message' => $e->getMessage()]);
        }
      }
    }
  }

  /**
   * Implements hook_views_data().
   *
   * Exposes sitewide content type audit metrics as a virtual Views table
   * backed by the SiteAggregationService.
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    $data = [];

    $data['ai_site_audit_content_type_stats'] = [
      'table' => [
        'group' => $this->t('AI Site Audit'),
        'provider' => 'ai_site_audit',
        'base' => [
          'field' => 'content_type',
          'title' => $this->t('Content Type Audit Statistics'),
          'help' => $this->t('Aggregated AI content audit statistics per content type.'),
          'query_id' => 'ai_site_audit_content_type_stats',
        ],
      ],
      'content_type' => [
        'title' => $this->t('Content Type'),
        'help' => $this->t('The machine name of the content type.'),
        'field' => ['id' => 'standard'],
        'sort' => ['id' => 'standard'],
        'filter' => ['id' => 'string'],
      ],
      'content_type_label' => [
        'title' => $this->t('Content Type Label'),
        'help' => $this->t('The human-readable label of the content type.'),
        'field' => ['id' => 'standard'],
      ],
      'count' => [
        'title' => $this->t('Assessment Count'),
        'help' => $this->t('Number of assessed nodes of this content type.'),
        'field' => ['id' => 'numeric'],
        'sort' => ['id' => 'standard'],
        'filter' => ['id' => 'numeric'],
      ],
      'avg_score' => [
        'title' => $this->t('Average Score'),
        'help' => $this->t('Average audit score for this content type.'),
        'field' => ['id' => 'numeric', 'float' => TRUE],
        'sort' => ['id' => 'standard'],
        'filter' => ['id' => 'numeric'],
      ],
      'min_score' => [
        'title' => $this->t('Minimum Score'),
        'help' => $this->t('Lowest audit score for this content type.'),
        'field' => ['id' => 'numeric'],
        'sort' => ['id' => 'standard'],
      ],
      'max_score' => [
        'title' => $this->t('Maximum Score'),
        'help' => $this->t('Highest audit score for this content type.'),
        'field' => ['id' => 'numeric'],
        'sort' => ['id' => 'standard'],
      ],
    ];

    return $data;
  }

}
