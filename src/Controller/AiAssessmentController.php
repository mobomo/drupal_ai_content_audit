<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Controller;

use Drupal\ai_content_audit\Entity\AiContentAssessment;
use Drupal\ai_content_audit\Repository\AiContentAssessmentRepository;
use Drupal\ai_content_audit\Service\FilesystemAuditService;
use Drupal\ai_content_audit\Service\TechnicalAuditService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the AI assessment history tab and full report page.
 */
class AiAssessmentController extends ControllerBase {

  /**
   * Maps technical-audit check IDs to their display group and sort order.
   */
  private const CHECKS_ORDER_MAP = [
    'canonical_url'        => ['group' => 'Node Checks', 'order' => 0],
    'schema_markup'        => ['group' => 'Node Checks', 'order' => 1],
    'entity_relationships' => ['group' => 'Node Checks', 'order' => 2],
    'date_meta_tags'       => ['group' => 'Node Checks', 'order' => 3],
    'robots_txt'           => ['group' => 'Site-Level AI Signals', 'order' => 10],
    'llms_txt'             => ['group' => 'Site-Level AI Signals', 'order' => 11],
    'sitemap'              => ['group' => 'Site-Level AI Signals', 'order' => 12],
    'https'                => ['group' => 'Site-Level AI Signals', 'order' => 13],
    'language_declaration' => ['group' => 'Site-Level AI Signals', 'order' => 14],
    'content_licensing'    => ['group' => 'Site-Level AI Signals', 'order' => 15],
    'feed_availability'    => ['group' => 'Infrastructure', 'order' => 20],
    'json_api'             => ['group' => 'Infrastructure', 'order' => 21],
  ];

  /**
   * Maps filesystem check ID prefixes to their display category.
   */
  private const FS_PREFIX_MAP = [
    'fs_settings_'   => 'Security',
    'fs_htaccess'    => 'Security',
    'fs_git_'        => 'Security',
    'fs_dev_'        => 'Security',
    'fs_world_'      => 'Security',
    'fs_trusted_'    => 'Configuration',
    'fs_services_'   => 'Configuration',
    'fs_files_'      => 'Configuration',
    'fs_private_'    => 'Configuration',
    'fs_custom_'     => 'Module Inventory',
    'fs_orphaned_'   => 'Module Inventory',
    'fs_contrib_'    => 'Module Inventory',
    'fs_public_'     => 'Filesystem Health',
    'fs_temp_'       => 'Filesystem Health',
    'fs_large_'      => 'Filesystem Health',
    'fs_stale_'      => 'Filesystem Health',
    'fs_llms_'       => 'AI Readiness',
    'fs_robots_'     => 'AI Readiness',
    'fs_structured_' => 'AI Readiness',
  ];

  public function __construct(
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly AiContentAssessmentRepository $assessmentRepository,
    protected readonly TechnicalAuditService $technicalAuditService,
    protected readonly FilesystemAuditService $filesystemAuditService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
      $container->get('ai_content_audit.assessment_repository'),
      $container->get('ai_content_audit.technical_audit'),
      $container->get('ai_content_audit.filesystem_audit'),
    );
  }

  /**
   * Renders the assessment history for a node.
   *
   * The {node} parameter is automatically upcasted to NodeInterface by
   * Drupal's EntityConverter ParamConverter.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose assessment history to display.
   *
   * @return array
   *   A render array containing the history table.
   */
  public function history(NodeInterface $node): array {
    $assessments = $this->assessmentRepository->getAllForNode((int) $node->id(), 25);

    $rows = [];
    foreach ($assessments as $assessment) {
      $rows[] = [
        $this->dateFormatter->format(
          (int) $assessment->get('created')->value,
          'short',
        ),
        $assessment->getScore() . '/100',
        $assessment->get('provider_id')->value,
        $assessment->get('model_id')->value,
        [
          'data' => [
            '#type'  => 'link',
            '#title' => $this->t('View Report'),
            '#url'   => Url::fromRoute(
              'ai_content_audit.assessment.report',
              ['ai_content_assessment' => $assessment->id()],
            ),
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type'   => 'table',
      '#header' => [
        $this->t('Date'),
        $this->t('Score'),
        $this->t('Provider'),
        $this->t('Model'),
        $this->t('Operations'),
      ],
      '#rows'  => $rows,
      '#empty' => $this->t('No assessments have been run for this node yet.'),
    ];

    $build['#cache'] = [
      'tags'     => array_merge(
        $node->getCacheTags(),
        ['ai_content_assessment_list:node:' . $node->id()],
      ),
      'contexts' => ['url'],
    ];

    return $build;
  }

  /**
   * Title callback for the assessment report page.
   *
   * @param \Drupal\ai_content_audit\Entity\AiContentAssessment $ai_content_assessment
   *   The assessment entity.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function reportTitle(AiContentAssessment $ai_content_assessment): TranslatableMarkup {
    $node = $ai_content_assessment->getTargetNode();
    $node_title = $node ? $node->getTitle() : $this->t('Unknown Node');
    return $this->t('Assessment Report: @title', ['@title' => $node_title]);
  }

  /**
   * Renders the full formatted assessment report page.
   *
   * @param \Drupal\ai_content_audit\Entity\AiContentAssessment $ai_content_assessment
   *   The assessment entity to render (upcasted from route parameter).
   *
   * @return array
   *   A render array for the full report page.
   */
  public function report(AiContentAssessment $ai_content_assessment): array {
    $assessment = $ai_content_assessment;
    $node = $assessment->getTargetNode();

    $score       = $assessment->getScore() ?? 0;
    $result_json = $assessment->getParsedResult();
    $raw_output  = $assessment->get('raw_output')->value ?? '';
    $created     = (int) $assessment->get('created')->value;
    $provider_id = $assessment->get('provider_id')->value ?? '';
    $model_id    = $assessment->get('model_id')->value ?? '';
    $trend_delta = $assessment->getScoreTrendDelta();

    // Run-by user display name.
    $run_by = NULL;
    if (!$assessment->get('run_by')->isEmpty()) {
      /** @var \Drupal\user\UserInterface $user */
      $user   = $assessment->get('run_by')->entity;
      $run_by = $user?->getDisplayName();
    }

    // Sub-scores with percentage calculations.
    $sub_scores = $assessment->getSubScores() ?? [];
    foreach ($sub_scores as &$sub) {
      $sub['percentage'] = $sub['max_score'] > 0
        ? round(($sub['score'] / $sub['max_score']) * 100)
        : 0;
    }
    unset($sub);

    // Checkpoints grouped by category.
    $checkpoints             = $assessment->getCheckpoints() ?? [];
    $checkpoints_by_category = [];
    foreach ($checkpoints as $cp) {
      $category = $cp['category'] ?? 'Other';
      $checkpoints_by_category[$category][] = $cp;
    }

    // Action items grouped by priority.
    $action_items        = $assessment->getActionItems() ?? [];
    $action_items_status = $assessment->getActionItemsStatus() ?? [];
    $high_items          = [];
    $medium_items        = [];
    $low_items           = [];
    foreach ($action_items as $item) {
      $priority = $item['priority'] ?? 'low';
      match ($priority) {
        'high'   => ($high_items[]   = $item),
        'medium' => ($medium_items[] = $item),
        default  => ($low_items[] = $item),
      };
    }
    $completed_count = count(
      array_filter($action_items_status, static fn($s) => !empty($s['completed']))
    );

    // Full score history for this node.
    $history = $this->buildScoreHistory($node, (int) $assessment->id());

    // Technical audit (live run, no cache force).
    [$technical_checks, $technical_checks_grouped, $technical_pass_count] =
      $node ? $this->buildTechnicalAudit($node) : [[], [], 0];

    // Filesystem audit.
    [$fs_categories, $fs_summary] = $this->buildFilesystemAudit();

    // Node edit URL for the header back-link.
    $node_edit_url = $node
      ? Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])->toString()
      : NULL;

    // Formatted result JSON for copy block.
    $result_json_formatted = json_encode(
      $result_json,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return [
      '#theme'                => 'ai_assessment_report',
      '#assessment_id'        => $assessment->id(),
      '#node'                 => $node,
      '#node_edit_url'        => $node_edit_url,
      '#score'                => $score,
      '#qualitative_status'   => $result_json['qualitative_status'] ?? NULL,
      '#trend_delta'          => $trend_delta,
      '#created'              => $created,
      '#provider_id'          => $provider_id,
      '#model_id'             => $model_id,
      '#run_by'               => $run_by,
      '#sub_scores'           => $sub_scores,
      '#checkpoints'          => $checkpoints,
      '#checkpoints_by_category' => $checkpoints_by_category,
      '#high_items'           => $high_items,
      '#medium_items'         => $medium_items,
      '#low_items'            => $low_items,
      '#action_items_status'  => $action_items_status,
      '#completed_count'      => $completed_count,
      '#total_action_items'   => count($action_items),
      '#history'              => $history,
      '#technical_checks'     => $technical_checks,
      '#technical_checks_grouped' => $technical_checks_grouped,
      '#technical_pass_count' => $technical_pass_count,
      '#technical_total_count' => count($technical_checks),
      '#filesystem_categories' => $fs_categories,
      '#filesystem_summary'   => $fs_summary,
      '#readability'          => $result_json['readability'] ?? [],
      '#seo'                  => $result_json['seo'] ?? [],
      '#content_completeness' => $result_json['content_completeness'] ?? [],
      '#tone_consistency'     => $result_json['tone_consistency'] ?? [],
      '#heading_hierarchy'    => $result_json['heading_hierarchy'] ?? [],
      '#image_accessibility'  => $result_json['image_accessibility'] ?? [],
      '#link_analysis'        => $result_json['link_analysis'] ?? [],
      '#content_freshness'    => $result_json['content_freshness'] ?? [],
      '#entity_richness'      => $result_json['entity_richness'] ?? [],
      '#content_patterns'     => $result_json['content_patterns'] ?? [],
      '#rag_chunk_quality'    => $result_json['rag_chunk_quality'] ?? [],
      '#raw_output'           => $raw_output,
      '#result_json_formatted' => $result_json_formatted,
      '#attached' => [
        'library' => ['ai_content_audit/assessment-report'],
      ],
      '#cache' => [
        'tags'     => $assessment->getCacheTags(),
        'contexts' => ['url'],
      ],
    ];
  }

  /*
   * ---------------------------------------------------------------------------
   * Private helpers
   * ---------------------------------------------------------------------------
   */

  /**
   * Builds the score-history array for all assessments of the given node.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The target node.
   * @param int $current_id
   *   The ID of the assessment currently being rendered.
   *
   * @return array
   *   Array of history rows, each with keys: id, date, date_full, score,
   *   bar_height, delta, provider, model, is_current, url.
   */
  private function buildScoreHistory(?NodeInterface $node, int $current_id): array {
    if (!$node) {
      return [];
    }

    $storage = $this->entityTypeManager()->getStorage('ai_content_assessment');
    $ids     = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node->id())
      ->sort('created', 'ASC')
      ->execute();

    $history = [];
    if (!empty($ids)) {
      foreach ($storage->loadMultiple($ids) as $hist) {
        $hist_created = (int) $hist->get('created')->value;
        $hist_score   = (int) ($hist->get('score')->value ?? 0);
        $history[]    = [
          'id'         => $hist->id(),
          'date'       => $this->dateFormatter->format($hist_created, 'short'),
          'date_full'  => $this->dateFormatter->format($hist_created, 'long'),
          'score'      => $hist_score,
          'bar_height' => round(($hist_score / 100) * 100),
          'delta'      => $hist->getScoreTrendDelta(),
          'provider'   => $hist->get('provider_id')->value ?? '',
          'model'      => $hist->get('model_id')->value ?? '',
          'is_current' => ((int) $hist->id() === $current_id),
          'url'        => Url::fromRoute(
            'ai_content_audit.assessment.report',
            ['ai_content_assessment' => $hist->id()],
          )->toString(),
        ];
      }
    }

    return $history;
  }

  /**
   * Runs the technical audit and groups results.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The target node.
   *
   * @return array
   *   Three-element array: [flat checks, grouped checks, pass count].
   */
  private function buildTechnicalAudit(NodeInterface $node): array {
    $all_checks    = [];
    $grouped_label = [];
    $unmapped      = [];
    $pass_count    = 0;

    try {
      $results = $this->technicalAuditService->runAllChecks($node, FALSE);
      foreach ($results as $result) {
        $arr          = $result->toArray();
        $all_checks[] = $arr;
        if ($result->status === 'pass') {
          $pass_count++;
        }
        $key = $arr['check'] ?? '';
        if (isset(self::CHECKS_ORDER_MAP[$key])) {
          $meta = self::CHECKS_ORDER_MAP[$key];
          $grouped_label[$meta['group']][$meta['order']] = $arr;
        }
        else {
          $unmapped[] = $arr;
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('ai_content_audit')->warning(
        'Technical audit failed in report page: @msg',
        ['@msg' => $e->getMessage()]
      );
    }

    $grouped = [];
    foreach (['Node Checks', 'Site-Level AI Signals', 'Infrastructure'] as $label) {
      if (!empty($grouped_label[$label])) {
        ksort($grouped_label[$label]);
        $grouped[] = [
          'label'  => $label,
          'checks' => array_values($grouped_label[$label]),
        ];
      }
    }
    if (!empty($unmapped)) {
      $grouped[] = ['label' => NULL, 'checks' => $unmapped];
    }

    return [$all_checks, $grouped, $pass_count];
  }

  /**
   * Runs the filesystem audit and groups results by category.
   *
   * @return array
   *   Two-element array: [categories array, summary counts array].
   */
  private function buildFilesystemAudit(): array {
    $categories = [];
    $summary    = [
      'pass_count'    => 0,
      'fail_count'    => 0,
      'warning_count' => 0,
      'info_count'    => 0,
      'total_count'   => 0,
    ];

    try {
      $results = $this->filesystemAuditService->runAllChecks();
      foreach ($results as $r) {
        $check    = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
        $check_id = $check['check'] ?? '';
        $category = 'Other';
        foreach (self::FS_PREFIX_MAP as $prefix => $cat) {
          if (str_starts_with($check_id, $prefix)) {
            $category = $cat;
            break;
          }
        }
        $categories[$category][] = $check;

        $status = $check['status'] ?? 'info';
        match ($status) {
          'pass'    => $summary['pass_count']++,
          'fail'    => $summary['fail_count']++,
          'warning' => $summary['warning_count']++,
          'info'    => $summary['info_count']++,
          default   => NULL,
        };
        $summary['total_count']++;
      }
    }
    catch (\Exception $e) {
      $this->getLogger('ai_content_audit')->warning(
        'Filesystem audit failed in report page: @msg',
        ['@msg' => $e->getMessage()]
      );
    }

    return [$categories, $summary];
  }

}
