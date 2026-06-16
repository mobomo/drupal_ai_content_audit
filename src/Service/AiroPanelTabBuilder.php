<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_content_audit\Entity\AiContentAssessment;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds render arrays for AIRO panel tabs.
 */
final class AiroPanelTabBuilder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TechnicalAuditService $technicalAuditService,
    protected AiProviderPluginManager $aiProviderManager,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected ProviderModelChoices $providerModelChoices,
    protected RequestStack $requestStack,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Builds the render array for the AI Score tab.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being assessed.
   * @param mixed $assessment
   *   The latest AiContentAssessment entity, or NULL if none exists.
   *
   * @return array
   *   A Drupal render array using the ai_score_tab theme.
   */
  public function buildScoreTab(NodeInterface $node, mixed $assessment): array {
    $score = NULL;
    $sub_scores = [];
    $checkpoints_by_category = [];
    $trend_delta = NULL;
    $readability_grade = NULL;
    $tone = NULL;
    $h2_section_count = NULL;

    if ($assessment) {
      $score = (int) $assessment->get('score')->value;
      $trend_delta = $assessment->getScoreTrendDelta();

      // Sub-scores with percentage calculation.
      $raw_sub_scores = $assessment->getSubScores() ?? [];
      foreach ($raw_sub_scores as &$sub) {
        $sub['percentage'] = $sub['max_score'] > 0
          ? round(($sub['score'] / $sub['max_score']) * 100)
          : 0;
      }
      unset($sub);
      $sub_scores = $raw_sub_scores;

      // Checkpoints grouped by category.
      $raw_checkpoints = $assessment->getCheckpoints() ?? [];
      foreach ($raw_checkpoints as $cp) {
        $category = $cp['category'] ?? 'Other';
        $checkpoints_by_category[$category][] = $cp;
      }

      // Extract metadata from result_json for the overview metadata row.
      $result_json = $assessment->getParsedResult();
      $grade_raw = $result_json['readability']['grade_level'] ?? NULL;
      $readability_grade = $grade_raw !== NULL ? (int) $grade_raw : NULL;
      $tone_raw = $result_json['tone_consistency']['tone'] ?? NULL;
      $tone = !empty($tone_raw) ? (string) $tone_raw : NULL;
      $h2_raw = $result_json['rag_chunk_quality']['h2_section_count'] ?? NULL;
      $h2_section_count = $h2_raw !== NULL ? (int) $h2_raw : NULL;
    }

    // Compute days since modified from the node's last-changed timestamp.
    // Cast to int to avoid TypeError on PHP 8 strict typing.
    $changed_time = (int) $node->getChangedTime();
    $days_since_modified = NULL;
    $is_stale = FALSE;
    if ($changed_time > 0) {
      $days_since_modified = (int) floor((time() - $changed_time) / 86400);
      $is_stale = $days_since_modified > 90;
    }

    // Get score history (newest 10 assessments, displayed oldest->newest).
    $storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    $history_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 10)
      ->execute();

    $history = [];
    if (!empty($history_ids)) {
      /** @var \Drupal\ai_content_audit\Entity\AiContentAssessment $history_assessments */
      $history_assessments = $storage->loadMultiple($history_ids);
      foreach ($history_assessments as $hist) {
        $created = (int) $hist->get('created')->value;
        $hist_score = (int) $hist->get('score')->value;
        $history[] = [
          'date' => date('M j, Y', $created),
          'date_short' => date('M j', $created),
          'score' => $hist_score,
          'bar_height' => $hist_score,
        ];
      }
      // Reverse so the chart displays oldest on the left, newest on the right.
      $history = array_reverse($history);
    }

    return [
      '#theme' => 'ai_score_tab',
      '#score' => $score,
      '#sub_scores' => $sub_scores,
      '#checkpoints_by_category' => $checkpoints_by_category,
      '#history' => $history,
      '#trend_delta' => $trend_delta,
      '#node_id' => $node->id(),
      '#revision_id' => (int) $node->getRevisionId(),
      '#assess_url' => Url::fromRoute(
        'ai_content_audit.panel.assess',
        ['node' => $node->id()]
      )->toString(),
      '#readability_grade'   => $readability_grade,
      '#tone'                => $tone,
      '#h2_section_count'    => $h2_section_count,
      '#days_since_modified' => $days_since_modified,
      '#is_stale'            => $is_stale,
      '#attached' => [
        'library' => [
          'ai_content_audit/score-tab',
        ],
      ],
    ];
  }

  /**
   * Builds the render array for the Action Items tab.
   */
  public function buildActionItemsTab(NodeInterface $node): array {
    $storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    $assessment = !empty($ids) ? $storage->load(reset($ids)) : NULL;

    $action_items = [];
    $action_items_status = [];
    $high_items = [];
    $medium_items = [];
    $low_items = [];

    if ($assessment instanceof AiContentAssessment) {
      $action_items = $assessment->getActionItems() ?? [];
      $action_items_status = $assessment->getActionItemsStatus() ?? [];

      foreach ($action_items as $item) {
        $priority = $item['priority'] ?? 'low';
        switch ($priority) {
          case 'high':
            $high_items[] = $item;
            break;

          case 'medium':
            $medium_items[] = $item;
            break;

          default:
            $low_items[] = $item;
            break;
        }
      }
    }

    $completed_count = 0;
    foreach ($action_items_status as $status) {
      if (!empty($status['completed'])) {
        $completed_count++;
      }
    }

    return [
      '#theme' => 'ai_action_items_tab',
      '#high_items' => $high_items,
      '#medium_items' => $medium_items,
      '#low_items' => $low_items,
      '#action_items_status' => $action_items_status,
      '#total_count' => count($action_items),
      '#completed_count' => $completed_count,
      '#high_count' => count($high_items),
      '#node_id' => $node->id(),
      '#revision_id' => (int) $node->getRevisionId(),
      '#assess_url' => Url::fromRoute('ai_content_audit.panel.assess', ['node' => $node->id()])->toString(),
      '#attached' => [
        'library' => [
          'ai_content_audit/action-items-tab',
        ],
      ],
    ];
  }

  /**
   * Builds the render array for the Technical Audit tab.
   */
  public function buildTechnicalAuditTab(NodeInterface $node): array {
    $request = $this->requestStack->getCurrentRequest();
    $forceRefresh = $request?->query->get('force_refresh', FALSE) ?? FALSE;

    $results = $this->technicalAuditService->runAllChecks($node, (bool) $forceRefresh);

    $nodeSpecificChecks = [
      'canonical_url',
      'schema_markup',
      'entity_relationships',
      'date_meta_tags',
    ];

    $checks = [];
    $passCount = 0;
    foreach ($results as $result) {
      if (!in_array($result->check, $nodeSpecificChecks, TRUE)) {
        continue;
      }
      $checks[] = $result->toArray();
      if ($result->status === 'pass') {
        $passCount++;
      }
    }

    return [
      '#theme'       => 'ai_technical_audit_tab',
      '#checks'      => $checks,
      '#pass_count'  => $passCount,
      '#total_count' => count($checks),
      '#node_id'     => $node->id(),
      '#revision_id' => (int) $node->getRevisionId(),
      '#attached'    => [
        'library' => [
          'ai_content_audit/technical-audit-tab',
        ],
      ],
    ];
  }

  /**
   * Builds the render array for the AI Preview tab.
   */
  public function buildPreviewTab(NodeInterface $node, bool $pageSkin = FALSE): array {
    $hasPermission = $this->currentUser->hasPermission('use any ai provider in airo');

    $allChoices = $this->providerModelChoices->forOperationType('chat');

    if (!$hasPermission || empty($allChoices)) {
      $central = $this->aiProviderManager->getDefaultProviderForOperationType('content_audit')
        ?? $this->aiProviderManager->getDefaultProviderForOperationType('chat');
      if (!empty($central['provider_id'])) {
        $key = $central['provider_id'] . '__' . ($central['model_id'] ?? '');
        $label = ucwords(str_replace(['-', '_'], ' ', $central['provider_id']));
        $allChoices = [[
          'key' => $key,
          'label' => $label,
          'provider_id' => $central['provider_id'],
          'model_id' => $central['model_id'] ?? '',
        ],
        ];
      }
      else {
        $allChoices = [];
      }
    }

    $store = $this->tempStoreFactory->get('ai_content_audit');
    $savedKeys = $store->get('last_provider_models') ?? [];
    $validKeys = array_column($allChoices, 'key');
    $selectedKeys = array_values(
      array_filter($savedKeys, fn($k) => in_array($k, $validKeys, TRUE))
    );
    if (empty($selectedKeys) && !empty($validKeys)) {
      $selectedKeys = [$validKeys[0]];
    }

    $suggested_prompts = [
      'What are the key points of this content?',
      'How would you summarize this page?',
      'What questions does this content leave unanswered?',
    ];

    return [
      '#theme' => 'ai_preview_tab',
      '#use_page_skin' => $pageSkin,
      '#model_choices' => $allChoices,
      '#selected_keys' => $selectedKeys,
      '#has_permission' => $hasPermission,
      '#suggested_prompts' => $suggested_prompts,
      '#node_id' => $node->id(),
      '#revision_id' => (int) $node->getRevisionId(),
      '#query_url' => Url::fromRoute(
        'ai_content_audit.panel.preview_query',
        ['node' => $node->id()]
      )->toString(),
      '#providers_url' => Url::fromRoute('ai.admin_providers')->toString(),
      '#attached' => [
        'library' => [
          'ai_content_audit/preview-tab',
        ],
      ],
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
