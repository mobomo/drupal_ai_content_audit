<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Controller;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenOffCanvasDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ai_content_audit\Service\AiAssessmentService;
use Drupal\ai_content_audit\Service\FilesystemAuditService;
use Drupal\ai_content_audit\Service\ProviderModelChoices;
use Drupal\ai_content_audit\Service\TechnicalAuditService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for the AIRO off-canvas analysis panel.
 */
class AiroPanelController extends ControllerBase {

  public function __construct(
    protected AiAssessmentService $assessmentService,
    protected RendererInterface $renderer,
    protected TechnicalAuditService $technicalAuditService,
    protected AiProviderPluginManager $aiProviderManager,
    protected FilesystemAuditService $filesystemAuditService,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected ProviderModelChoices $providerModelChoices,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_content_audit.assessment_service'),
      $container->get('renderer'),
      $container->get('ai_content_audit.technical_audit'),
      $container->get('ai.provider'),
      $container->get('ai_content_audit.filesystem_audit'),
      $container->get('tempstore.private'),
      $container->get('ai_content_audit.provider_model_choices'),
    );
  }

  /**
   * Opens the AIRO analysis panel in the off-canvas dialog.
   *
   * All four tab panes are rendered server-side and embedded in the initial
   * dialog HTML. Tab switching is handled via pure JS show/hide — no per-tab
   * AJAX requests. This eliminates the Drupal 10+ async attachBehaviors race
   * condition that caused the active tab to reset on every tab click.
   */
  public function openPanel(NodeInterface $node): AjaxResponse {
    // Load the latest assessment for score display.
    $storage = $this->entityTypeManager()->getStorage('ai_content_assessment');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    $assessment = !empty($ids) ? $storage->load(reset($ids)) : NULL;

    // Build all four tab panes up front so tab switching requires no AJAX.
    $pane_builds = [
      'preview-tab'         => $this->buildPreviewTab($node),
      'score-tab'           => $this->buildScoreTab($node, $assessment),
      'action-items-tab'    => $this->buildActionItemsTab($node),
      'technical-audit-tab' => $this->buildTechnicalAuditTab($node),
    ];

    // Render each pane to an HTML string and merge its library attachments.
    $tab_panes_html  = [];
    $merged_attached = ['library' => ['ai_content_audit/airo-panel']];
    foreach ($pane_builds as $tab_id => $pane_build) {
      $tab_panes_html[$tab_id] = (string) $this->renderer->renderRoot($pane_build);
      if (!empty($pane_build['#attached'])) {
        $merged_attached = array_merge_recursive($merged_attached, $pane_build['#attached']);
      }
    }

    // G4: Build URLs for the sticky footer actions.
    // "View Full Report" links to the assessment entity page if one exists;
    // fall back to NULL so the template can omit the button gracefully.
    $full_report_url = $assessment
      ? Url::fromRoute('ai_content_audit.assessment.report', ['ai_content_assessment' => $assessment->id()])->toString()
      : NULL;

    $build = [
      '#theme'           => 'ai_airo_panel',
      '#node_id'         => $node->id(),
      '#score'           => $assessment?->get('score')->value,
      '#node_title'      => $node->getTitle(),
      '#is_analyzing'    => FALSE,
      '#active_tab'      => 'preview-tab',
      '#tab_panes'       => $tab_panes_html,
      '#attached'        => $merged_attached,
      // G4: footer action URLs passed to the template.
      '#assess_url'      => Url::fromRoute('ai_content_audit.panel.assess', ['node' => $node->id()])->toString(),
      '#full_report_url' => $full_report_url,
    ];

    // renderRoot() consumes $build['#attached'], so use the pre-built
    // $merged_attached variable directly to guarantee the airo-panel JS
    // library is sent to the browser and Drupal.behaviors.airoPanel attaches.
    $html = $this->renderer->renderRoot($build);

    $response = new AjaxResponse();
    // Widen the off-canvas so the 4-tab bar fits without wrapping.
    $response->addCommand(new OpenOffCanvasDialogCommand(
      (string) $this->t('Airo analysis: @title', ['@title' => $node->label()]),
      (string) $html,
      [
        'width' => 560,
        'classes' => [
          'ui-dialog' => 'airo-panel-dialog',
        ],
      ],
      NULL,
      'side',
    ));
    $response->setAttachments($merged_attached);

    return $response;
  }

  /**
   * Triggers an AI assessment for the given node via AJAX.
   *
   * Note: AiAssessmentService::assessNode() returns an array with keys:
   * 'success', 'error', 'raw_output', 'parsed'. After a successful run,
   * the service creates and saves the assessment entity internally, so we
   * reload the latest entity to return its ID and score.
   */
  public function assessNode(NodeInterface $node): JsonResponse {
    try {
      $result = $this->assessmentService->assessNode($node);

      if (!$result['success']) {
        $this->getLogger('ai_content_audit')->error('Assessment failed: @message', [
          '@message' => $result['error'] ?? 'Unknown error',
        ]);
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('Assessment failed: @error', ['@error' => $result['error'] ?? 'Unknown error']),
        ], 500);
      }

      // Load the freshly saved assessment entity to get its ID and score.
      $storage = $this->entityTypeManager()->getStorage('ai_content_assessment');
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('target_node', $node->id())
        ->sort('created', 'DESC')
        ->range(0, 1)
        ->execute();

      $score = NULL;
      $assessment_id = NULL;
      if (!empty($ids)) {
        $assessment = $storage->load(reset($ids));
        $score = $assessment?->get('score')->value;
        $assessment_id = $assessment?->id();
      }

      return new JsonResponse([
        'status' => 'complete',
        'score' => $score,
        'assessment_id' => $assessment_id,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('ai_content_audit')->error('Assessment failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Assessment failed. Please try again.'),
      ], 500);
    }
  }

  /**
   * G5: Re-renders the inline score widget for a given node and returns a
   * Drupal AJAX ReplaceCommand so the browser can swap only the widget card
   * without a full page reload.
   *
   * The selector targets `.airo-widget[data-node-id="N"]` so multiple widgets
   * on the same page (e.g. multiple tabs) are handled correctly.
   */
  public function refreshWidget(NodeInterface $node): AjaxResponse {
    $storage = $this->entityTypeManager()->getStorage('ai_content_assessment');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    $assessment = !empty($ids) ? $storage->load(reset($ids)) : NULL;
    $score      = $assessment ? (int) $assessment->get('score')->value : NULL;
    $has_assessment = $assessment !== NULL;

    // Map score to GIN-aligned color token name and status label.
    if ($score === NULL) {
      $score_color   = 'danger';
      $status_label  = $this->t('Not analyzed');
    }
    elseif ($score >= 70) {
      $score_color  = 'good';
      $status_label = $this->t('AI Ready');
    }
    elseif ($score >= 40) {
      $score_color  = 'warning';
      $status_label = $this->t('Needs Work');
    }
    else {
      $score_color  = 'danger';
      $status_label = $this->t('Needs Improvement');
    }

    // SVG donut geometry.
    $donut_radius         = 26;
    $donut_circumference  = round(2 * M_PI * $donut_radius, 2);
    $donut_offset         = $score !== NULL
      ? round($donut_circumference * (1 - $score / 100), 2)
      : $donut_circumference;

    // Count high-priority action items.
    $action_items        = $assessment ? ($assessment->getActionItems() ?? []) : [];
    $high_priority_count = count(array_filter(
      $action_items,
      static fn(array $item) => ($item['priority'] ?? 'low') === 'high'
    ));

    // View Analysis link — use-ajax so it opens the off-canvas panel.
    $view_analysis_link = [
      '#type'       => 'link',
      '#title'      => $this->t('View Analysis'),
      '#url'        => Url::fromRoute('ai_content_audit.panel.open', ['node' => $node->id()]),
      '#attributes' => [
        'class'                 => ['button', 'button--small', 'use-ajax'],
        'data-dialog-type'      => 'dialog',
        'data-dialog-renderer'  => 'off_canvas',
        'aria-label'            => $this->t('View AI analysis panel'),
      ],
    ];

    $assess_url = Url::fromRoute('ai_content_audit.panel.assess', ['node' => $node->id()])->toString();

    $widget_build = [
      '#theme'              => 'ai_inline_score_widget',
      '#node_id'            => $node->id(),
      '#score'              => $score,
      '#is_analyzing'       => FALSE,
      '#has_assessment'     => $has_assessment,
      '#high_priority_count' => $high_priority_count,
      '#score_color'        => $score_color,
      '#status_label'       => $status_label,
      '#donut_radius'       => $donut_radius,
      '#donut_circumference' => $donut_circumference,
      '#donut_offset'       => $donut_offset,
      '#view_analysis_link' => $view_analysis_link,
      '#assess_url'         => $assess_url,
      '#attached'           => ['library' => ['ai_content_audit/inline-widget']],
    ];

    $html = (string) $this->renderer->renderRoot($widget_build);

    // Target only the widget for this specific node — keeps sibling widgets intact.
    $selector = '.airo-widget[data-node-id="' . $node->id() . '"]';

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand($selector, $html));
    $response->setAttachments($widget_build['#attached'] ?? []);
    return $response;
  }

  /**
   * Returns the current assessment status for polling.
   */
  public function assessmentStatus(NodeInterface $node): JsonResponse {
    $storage = $this->entityTypeManager()->getStorage('ai_content_assessment');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!empty($ids)) {
      $assessment = $storage->load(reset($ids));
      return new JsonResponse([
        'status' => 'complete',
        'score' => $assessment->get('score')->value,
      ]);
    }

    return new JsonResponse([
      'status' => 'pending',
      'score' => NULL,
    ]);
  }

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

    // Get score history (newest 10 assessments, displayed oldest→newest).
    $storage = $this->entityTypeManager()->getStorage('ai_content_assessment');
    $history_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 10)
      ->execute();

    $history = [];
    if (!empty($history_ids)) {
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
      '#assess_url' => \Drupal\Core\Url::fromRoute(
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
    $storage = $this->entityTypeManager()->getStorage('ai_content_assessment');
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

    if ($assessment) {
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
      '#assess_url' => \Drupal\Core\Url::fromRoute('ai_content_audit.panel.assess', ['node' => $node->id()])->toString(),
      '#attached' => [
        'library' => [
          'ai_content_audit/action-items-tab',
        ],
      ],
    ];
  }

  /**
   * Toggles the completion state of an action item.
   */
  public function toggleActionItem(NodeInterface $node, string $item_id): JsonResponse {
    $storage = $this->entityTypeManager()->getStorage('ai_content_assessment');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return new JsonResponse(['error' => 'No assessment found'], 404);
    }

    $assessment = $storage->load(reset($ids));
    $status = $assessment->getActionItemsStatus() ?? [];

    // Get the request body.
    $request = \Drupal::request();
    $body = json_decode($request->getContent(), TRUE);
    $completed = !empty($body['completed']);

    if ($completed) {
      $status[$item_id] = [
        'completed' => TRUE,
        'completed_by' => (int) \Drupal::currentUser()->id(),
        'completed_at' => date('c'),
      ];
    }
    else {
      unset($status[$item_id]);
    }

    $assessment->setActionItemsStatus($status);
    $assessment->save();

    // Count completed items.
    $completed_count = 0;
    foreach ($status as $s) {
      if (!empty($s['completed'])) {
        $completed_count++;
      }
    }

    return new JsonResponse([
      'status' => 'ok',
      'item_id' => $item_id,
      'completed' => $completed,
      'completed_count' => $completed_count,
    ]);
  }

  /**
   * Builds the render array for the Technical Audit tab (overlay / per-node).
   *
   * Only the four node-specific checks are shown here. Site-wide checks
   * (robots_txt, llms_txt, sitemap, https, feed_availability,
   * language_declaration, json_api, content_licensing) and filesystem checks
   * are intentionally excluded — they are still displayed on the site-wide
   * dashboard and the full assessment report page.
   */
  public function buildTechnicalAuditTab(NodeInterface $node): array {
    // Check if force refresh was requested.
    $request = \Drupal::request();
    $forceRefresh = $request->query->get('force_refresh', FALSE);

    $results = $this->technicalAuditService->runAllChecks($node, (bool) $forceRefresh);

    // Filter to only node-specific checks; site-wide checks belong in the
    // site-wide dashboard and the full report page, not the per-node overlay.
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
      '#attached'    => [
        'library' => [
          'ai_content_audit/technical-audit-tab',
        ],
      ],
    ];
  }

  /**
   * Builds the render array for the AI Preview tab.
   *
   * Passes model_choices (all configured provider+model combos for 'chat'),
   * selected_keys (last-used from tempstore or first available), and a
   * user.permissions cache context so the permission-gated selector is not
   * served incorrectly from cache.
   */
  public function buildPreviewTab(NodeInterface $node): array {
    $hasPermission = $this->currentUser()->hasPermission('use any ai provider in airo');

    // Get all configured chat-capable provider+model pairs.
    $allChoices = $this->providerModelChoices->forOperationType('chat');

    // Users without the permission only see the site default.
    if (!$hasPermission || empty($allChoices)) {
      $central = $this->aiProviderManager->getDefaultProviderForOperationType('content_audit')
        ?? $this->aiProviderManager->getDefaultProviderForOperationType('chat');
      if (!empty($central['provider_id'])) {
        $key   = $central['provider_id'] . '__' . ($central['model_id'] ?? '');
        $label = ucwords(str_replace(['-', '_'], ' ', $central['provider_id']));
        $allChoices = [[
          'key'         => $key,
          'label'       => $label,
          'provider_id' => $central['provider_id'],
          'model_id'    => $central['model_id'] ?? '',
        ]];
      }
      else {
        $allChoices = [];
      }
    }

    // Load last-used selection from per-user private tempstore.
    $store      = $this->tempStoreFactory->get('ai_content_audit');
    $savedKeys  = $store->get('last_provider_models') ?? [];
    $validKeys  = array_column($allChoices, 'key');
    // Keep only saved keys that still exist in the current choice list.
    $selectedKeys = array_values(
      array_filter($savedKeys, fn($k) => in_array($k, $validKeys, TRUE))
    );
    // Default: first available choice if nothing was previously saved.
    if (empty($selectedKeys) && !empty($validKeys)) {
      $selectedKeys = [$validKeys[0]];
    }

    $suggested_prompts = [
      'What are the key points of this content?',
      'How would you summarize this page?',
      'What questions does this content leave unanswered?',
    ];

    return [
      '#theme'            => 'ai_preview_tab',
      '#model_choices'    => $allChoices,
      '#selected_keys'    => $selectedKeys,
      '#has_permission'   => $hasPermission,
      '#suggested_prompts' => $suggested_prompts,
      '#node_id'          => $node->id(),
      '#query_url'        => \Drupal\Core\Url::fromRoute(
        'ai_content_audit.panel.preview_query',
        ['node' => $node->id()]
      )->toString(),
      '#attached' => [
        'library' => [
          'ai_content_audit/preview-tab',
        ],
      ],
      // Required: prevents Dynamic Page Cache from serving the wrong version
      // (with or without the selector) to users with different permissions.
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Handles multi-provider preview query POST and returns an N-result response.
   *
   * Request body JSON:
   *   { "question": "...", "provider_models": ["openai__gpt-4o-mini", ...] }
   *
   * Response JSON:
   *   { "results": [{ "key", "provider_id", "model_id", "label",
   *                   "html", "duration_ms", "error" }, ...],
   *     "question": "..." }
   *
   * One failing provider never causes an HTTP error for the whole response;
   * its error string is embedded in the per-result "error" field.
   */
  public function submitPreviewQuery(NodeInterface $node): JsonResponse {
    $request  = \Drupal::request();
    $body     = json_decode($request->getContent(), TRUE);
    $question = trim($body['question'] ?? '');

    if ($question === '') {
      return new JsonResponse(['error' => 'Please enter a question.'], 400);
    }

    // Determine which provider+model keys to query.
    $requestedKeys  = array_filter((array) ($body['provider_models'] ?? []));
    $hasPermission  = $this->currentUser()->hasPermission('use any ai provider in airo');

    // Fall back to site default for users without the permission or when no
    // specific providers were requested.
    if (!$hasPermission || empty($requestedKeys)) {
      $central = $this->aiProviderManager->getDefaultProviderForOperationType('content_audit')
        ?? $this->aiProviderManager->getDefaultProviderForOperationType('chat');
      if (!empty($central['provider_id'])) {
        $requestedKeys = [$central['provider_id'] . '__' . ($central['model_id'] ?? '')];
      }
      else {
        return new JsonResponse(['error' => 'No AI provider is configured.'], 500);
      }
    }

    // Build the shared prompts once — the page content doesn't change per provider.
    $nodeContent  = $this->extractNodeContent($node);
    $systemPrompt = 'You are simulating how an AI system would answer questions about web content. '
      . "You have been given the full text of a web page. Answer the user's question "
      . 'based solely on this content. If the content does not contain enough information '
      . 'to fully answer, note what is missing. Format your response in clear paragraphs.';
    $userPrompt   = "PAGE CONTENT:\n---\n{$nodeContent}\n---\n\n"
      . "USER QUESTION: {$question}\n\n"
      . 'Provide your answer based on the page content above.';

    // Build a map of key → label for display purposes.
    $labelMap = array_column(
      $this->providerModelChoices->forOperationType('chat'),
      'label',
      'key'
    );

    // Query each provider+model sequentially (Phase 1 — sequential, all-at-once).
    $results     = [];
    $successKeys = [];

    foreach ($requestedKeys as $key) {
      [$providerId, $modelId] = $this->providerModelChoices->parseKey((string) $key);
      if (empty($providerId)) {
        continue;
      }
      $label    = $labelMap[$key] ?? ucwords(str_replace(['-', '_'], ' ', $providerId));
      $oneResult = $this->queryOneProvider($systemPrompt, $userPrompt, $providerId, $modelId);

      $results[] = [
        'key'         => $key,
        'provider_id' => $providerId,
        'model_id'    => $modelId,
        'label'       => $label,
        'html'        => $oneResult['html'],
        'duration_ms' => $oneResult['duration_ms'],
        'error'       => $oneResult['error'],
      ];

      if ($oneResult['error'] === NULL) {
        $successKeys[] = $key;
      }
    }

    // Persist the last-used selection so the next panel open pre-ticks them.
    if (!empty($successKeys)) {
      $store = $this->tempStoreFactory->get('ai_content_audit');
      $store->set('last_provider_models', $successKeys);
    }

    // Results are query-specific; never serve from Drupal's page cache.
    $cacheability = (new CacheableMetadata())->setCacheMaxAge(0);
    $response = new CacheableJsonResponse([
      'results'  => $results,
      'question' => $question,
    ]);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Calls one AI provider+model and returns a normalised result array.
   *
   * Always succeeds at the PHP level: exceptions are caught and returned in the
   * 'error' key so one failing provider never aborts the whole comparison run.
   *
   * @return array{html: string|null, duration_ms: int, error: string|null}
   */
  private function queryOneProvider(
    string $systemPrompt,
    string $userPrompt,
    string $providerId,
    string $modelId,
  ): array {
    $start = microtime(TRUE);

    try {
      if (!$this->aiProviderManager->hasProvidersForOperationType('chat')) {
        throw new \RuntimeException('No AI chat provider is configured.');
      }

      $chatInput = new ChatInput([
        new ChatMessage('system', $systemPrompt),
        new ChatMessage('user', $userPrompt),
      ]);

      $proxy  = $this->aiProviderManager->createInstance($providerId);
      $output = $proxy->chat($chatInput, $modelId, ['ai_content_audit', 'preview']);
      $text   = $output->getNormalized()->getText();

      return [
        'html'        => $this->simpleMarkdownToHtml($text),
        'duration_ms' => (int) round((microtime(TRUE) - $start) * 1000),
        'error'       => NULL,
      ];
    }
    catch (\Exception $e) {
      $this->getLogger('ai_content_audit')->error(
        'Preview query failed for @provider/@model: @msg',
        [
          '@provider' => $providerId,
          '@model'    => $modelId,
          '@msg'      => $e->getMessage(),
        ]
      );
      return [
        'html'        => NULL,
        'duration_ms' => (int) round((microtime(TRUE) - $start) * 1000),
        'error'       => $e->getMessage(),
      ];
    }
  }

  /**
   * Returns a JSON list of all configured provider+model choices for the given
   * AI operation type.  Used by the AIRO panel JS to refresh the selector
   * (Phase 2 lazy-fetch stub — currently not called from the UI).
   *
   * Query parameter: op_type (default: 'chat').
   */
  public function listAvailableModels(): JsonResponse {
    $opType  = \Drupal::request()->query->get('op_type', 'chat');
    $choices = $this->providerModelChoices->forOperationType((string) $opType);

    // Model list is session/user-specific dynamic data — never cache.
    $cacheability = (new CacheableMetadata())->setCacheMaxAge(0);
    $response = new CacheableJsonResponse(['choices' => $choices]);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Extracts plain-text content from a node for use in an AI prompt.
   */
  private function extractNodeContent(NodeInterface $node): string {
    $content = 'Title: ' . $node->getTitle() . "\n\n";

    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      /** @var \Drupal\text\Plugin\Field\FieldType\TextWithSummaryItem $body */
      $body = $node->get('body')->first();
      $content .= strip_tags($body->value ?? '') . "\n";
    }

    return $content;
  }

  /**
   * Converts a basic subset of Markdown to HTML for response display.
   */
  private function simpleMarkdownToHtml(string $text): string {
    // Escape HTML entities first to prevent XSS.
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Headers (must run before bold to avoid double-processing).
    $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);

    // Bold.
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

    // Bullet list items.
    $text = preg_replace('/^- (.+)$/m', '<li>$1</li>', $text);
    // Wrap consecutive <li> elements in a <ul>.
    $text = preg_replace('/(<li>.*?<\/li>\n?)+/s', '<ul>$0</ul>', $text);

    // Paragraphs — split on two or more newlines.
    $text = preg_replace('/\n{2,}/', '</p><p>', $text);
    $text = '<p>' . $text . '</p>';

    // Clean up empty paragraphs or paragraphs wrapping block elements.
    $text = preg_replace('/<p>\s*<\/p>/', '', $text);
    $text = preg_replace('/<p>(<(?:ul|ol|h[2-6])[^>]*>)/i', '$1', $text);
    $text = preg_replace('/(<\/(?:ul|ol|h[2-6])>)<\/p>/i', '$1', $text);

    return $text;
  }

}
