<?php

declare(strict_types=1);

namespace Drupal\ai_site_audit\Plugin\views\query;

use Drupal\ai_site_audit\Service\SiteAggregationService;
use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Views query plugin that returns content type audit statistics.
 *
 * @ViewsQuery(
 *   id = "ai_site_audit_content_type_stats",
 *   title = @Translation("AI Site Audit Content Type Stats"),
 *   help = @Translation("Queries aggregated content type statistics from the AI Site Audit aggregation service.")
 * )
 */
class ContentTypeStatsQuery extends QueryPluginBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
          $plugin_id,
          $plugin_definition,
    protected SiteAggregationService $aggregationService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai_site_audit.aggregation'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function ensureTable($table, $relationship = NULL) {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function addField($table, $field, $alias = '', $params = []) {
    return $field;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(ViewExecutable $view) {
    $breakdown = $this->aggregationService->getContentTypeBreakdown();

    $index = 0;
    foreach ($breakdown as $item) {
      $row = new ResultRow([
        'content_type' => $item['type'] ?? '',
        'content_type_label' => $item['label'] ?? ucfirst(str_replace('_', ' ', $item['type'] ?? '')),
        'count' => (int) ($item['count'] ?? 0),
        'avg_score' => (float) ($item['avg_score'] ?? 0),
        'min_score' => (int) ($item['min_score'] ?? 0),
        'max_score' => (int) ($item['max_score'] ?? 0),
      ]);
      $row->index = $index++;
      $view->result[] = $row;
    }

    $view->total_rows = count($view->result);
    // @phpstan-ignore-next-line
    $view->execute_time = 0;
  }

}
