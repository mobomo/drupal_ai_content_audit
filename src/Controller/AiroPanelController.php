<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Controller;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenOffCanvasDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Drupal\ai_content_audit\Service\AiAssessmentService;
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
    );
  }

  /**
   * Opens the AIRO analysis panel in the off-canvas dialog.
   */
  public function openPanel(NodeInterface $node): AjaxResponse {
    // Get the latest assessment for this node.
    $storage = $this->entityTypeManager()->getStorage('ai_content_assessment');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    $assessment = !empty($ids) ? $storage->load(reset($ids)) : NULL;

    // Render the initial tab content (AI Score tab is default).
    $score_tab_build = $this->buildScoreTab($node, $assessment);
    $tab_content = $this->renderer->renderRoot($score_tab_build);
    // Collect any attached libraries from the inner tab render.
    $inner_attached = $score_tab_build['#attached'] ?? [];

    $build = [
      '#theme' => 'ai_airo_panel',
      '#node_id' => $node->id(),
      '#score' => $assessment?->get('score')->value,
      '#node_title' => $node->getTitle(),
      '#is_analyzing' => FALSE,
      '#active_tab' => 'score-tab',
      '#tab_content' => $tab_content,
      '#attached' => array_merge_recursive(
        ['library' => ['ai_content_audit/airo-panel']],
        $inner_attached
      ),
    ];

    $html = $this->renderer->renderRoot($build);

    $response = new AjaxResponse();
    $response->addCommand(new OpenOffCanvasDialogCommand(
      (string) $this->t('AIRO Analysis'),
      (string) $html,
      [
        'width' => 480,
        'classes' => [
          'ui-dialog' => 'airo-panel-dialog',
        ],
      ],
      NULL,
      'side',
    ));
    if (!empty($build['#attached'])) {
      $response->setAttachments($build['#attached']);
    }

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
   * Returns the AI Score tab content.
   */
  public function scoreTab(NodeInterface $node): AjaxResponse {
    $storage = $this->entityTypeManager()->getStorage('ai_content_assessment');

    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    $assessment = !empty($ids) ? $storage->load(reset($ids)) : NULL;

    $build = $this->buildScoreTab($node, $assessment);
    $html = $this->renderer->renderRoot($build);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand(
      '#airo-tab-content',
      '<div class="airo-panel__content" id="airo-tab-content" role="tabpanel">' . $html . '</div>'
    ));
    if (!empty($build['#attached'])) {
      $response->setAttachments($build['#attached']);
    }
    return $response;
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
  private function buildScoreTab(NodeInterface $node, mixed $assessment): array {
    $score = NULL;
    $sub_scores = [];
    $checkpoints = [];
    $checkpoints_by_category = [];
    $trend_delta = NULL;

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
      $checkpoints = $raw_checkpoints;
    }

    // Get score history (last 10 assessments).
    $storage = $this->entityTypeManager()->getStorage('ai_content_assessment');
    $history_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node->id())
      ->sort('created', 'ASC')
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
          'bar_height' => round(($hist_score / 100) * 100),
        ];
      }
    }

    return [
      '#theme' => 'ai_score_tab',
      '#score' => $score,
      '#sub_scores' => $sub_scores,
      '#checkpoints' => $checkpoints,
      '#checkpoints_by_category' => $checkpoints_by_category,
      '#history' => $history,
      '#trend_delta' => $trend_delta,
      '#node_id' => $node->id(),
      '#assess_url' => \Drupal\Core\Url::fromRoute(
        'ai_content_audit.panel.assess',
        ['node' => $node->id()]
      )->toString(),
      '#attached' => [
        'library' => [
          'ai_content_audit/score-tab',
        ],
      ],
    ];
  }

  /**
   * Returns the Action Items tab content.
   */
  public function actionItemsTab(NodeInterface $node): AjaxResponse {
    $build = $this->buildActionItemsTab($node);
    $html = $this->renderer->renderRoot($build);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand(
      '#airo-tab-content',
      '<div class="airo-panel__content" id="airo-tab-content" role="tabpanel">' . $html . '</div>'
    ));
    if (!empty($build['#attached'])) {
      $response->setAttachments($build['#attached']);
    }
    return $response;
  }

  /**
   * Builds the render array for the Action Items tab.
   */
  private function buildActionItemsTab(NodeInterface $node): array {
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
   * Returns the Technical Audit tab content.
   */
  public function technicalAuditTab(NodeInterface $node): AjaxResponse {
    $build = $this->buildTechnicalAuditTab($node);
    $html = $this->renderer->renderRoot($build);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand(
      '#airo-tab-content',
      '<div class="airo-panel__content" id="airo-tab-content" role="tabpanel">' . $html . '</div>'
    ));
    if (!empty($build['#attached'])) {
      $response->setAttachments($build['#attached']);
    }
    return $response;
  }

  /**
   * Builds the render array for the Technical Audit tab.
   */
  private function buildTechnicalAuditTab(NodeInterface $node): array {
    // Check if force refresh was requested.
    $request = \Drupal::request();
    $forceRefresh = $request->query->get('force_refresh', FALSE);

    $results = $this->technicalAuditService->runAllChecks($node, (bool) $forceRefresh);

    $checks = [];
    $passCount = 0;
    foreach ($results as $result) {
      $checks[] = $result->toArray();
      if ($result->status === 'pass') {
        $passCount++;
      }
    }

    return [
      '#theme' => 'ai_technical_audit_tab',
      '#checks' => $checks,
      '#pass_count' => $passCount,
      '#total_count' => count($checks),
      '#node_id' => $node->id(),
      '#attached' => [
        'library' => [
          'ai_content_audit/technical-audit-tab',
        ],
      ],
    ];
  }

  /**
   * Returns the AI Preview tab content.
   */
  public function aiPreviewTab(NodeInterface $node): AjaxResponse {
    $build = $this->buildPreviewTab($node);
    $html = $this->renderer->renderRoot($build);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand(
      '#airo-tab-content',
      '<div class="airo-panel__content" id="airo-tab-content" role="tabpanel">' . $html . '</div>'
    ));
    if (!empty($build['#attached'])) {
      $response->setAttachments($build['#attached']);
    }
    return $response;
  }

  /**
   * Builds the render array for the AI Preview tab.
   */
  private function buildPreviewTab(NodeInterface $node): array {
    $providers = $this->getAvailableProviders();
    $active_provider = !empty($providers) ? $providers[0]['id'] : NULL;

    $suggested_prompts = [
      'What are the key points of this content?',
      'How would you summarize this page?',
      'What questions does this content leave unanswered?',
    ];

    return [
      '#theme' => 'ai_preview_tab',
      '#providers' => $providers,
      '#suggested_prompts' => $suggested_prompts,
      '#active_provider' => $active_provider,
      '#node_id' => $node->id(),
      '#query_url' => \Drupal\Core\Url::fromRoute(
        'ai_content_audit.panel.preview_query',
        ['node' => $node->id()]
      )->toString(),
      '#attached' => [
        'library' => [
          'ai_content_audit/preview-tab',
        ],
      ],
    ];
  }

  /**
   * Discovers available AI providers from the AI module's provider manager.
   *
   * Returns an array of provider definitions:
   * [['id' => string, 'label' => string, 'model' => string], ...]
   */
  private function getAvailableProviders(): array {
    $providers = [];

    try {
      // Prefer a 'content_audit' operation-type default, then fall back to 'chat'.
      $central = $this->aiProviderManager->getDefaultProviderForOperationType('content_audit')
        ?? $this->aiProviderManager->getDefaultProviderForOperationType('chat');

      if (!empty($central['provider_id'])) {
        $providerId = $central['provider_id'];
        $modelId    = $central['model_id'] ?? '';
        // Build a human-readable label from the machine name.
        $label = ucwords(str_replace(['-', '_'], ' ', $providerId));
        $providers[] = [
          'id'    => $providerId,
          'label' => $label,
          'model' => $modelId,
        ];
      }
    }
    catch (\Exception $e) {
      $this->getLogger('ai_content_audit')->warning(
        'Could not discover AI providers: @msg',
        ['@msg' => $e->getMessage()]
      );
    }

    // Fallback placeholder so the UI is never completely empty.
    if (empty($providers)) {
      $providers[] = [
        'id'    => 'default',
        'label' => 'AI Assistant',
        'model' => '',
      ];
    }

    return $providers;
  }

  /**
   * Handles a preview query POST submission and returns a JSON response.
   */
  public function submitPreviewQuery(NodeInterface $node): JsonResponse {
    $request = \Drupal::request();
    $body     = json_decode($request->getContent(), TRUE);
    $question = trim($body['question'] ?? '');
    $providerId = $body['provider_id'] ?? NULL;

    if ($question === '') {
      return new JsonResponse(['error' => 'Please enter a question.'], 400);
    }

    try {
      $nodeContent = $this->extractNodeContent($node);

      $systemPrompt = "You are simulating how an AI system would answer questions about web content. "
        . "You have been given the full text of a web page. Answer the user's question "
        . "based solely on this content. If the content does not contain enough information "
        . "to fully answer, note what is missing. Format your response in clear paragraphs.";

      $userPrompt = "PAGE CONTENT:\n---\n{$nodeContent}\n---\n\n"
        . "USER QUESTION: {$question}\n\n"
        . "Provide your answer based on the page content above.";

      $responseText = $this->sendAiQuery($systemPrompt, $userPrompt, $providerId);
      $responseHtml = $this->simpleMarkdownToHtml($responseText);

      // Resolve a human-readable provider label.
      $providerLabel = 'AI';
      $providers = $this->getAvailableProviders();
      foreach ($providers as $p) {
        if ($p['id'] === $providerId || ($providerId === NULL)) {
          $providerLabel = $p['label'];
          break;
        }
      }

      return new JsonResponse([
        'response_html'  => $responseHtml,
        'response_text'  => $responseText,
        'provider_id'    => $providerId,
        'provider_label' => $providerLabel,
        'timestamp'      => date('g:i A'),
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('ai_content_audit')->error(
        'Preview query failed: @msg',
        ['@msg' => $e->getMessage()]
      );
      return new JsonResponse([
        'error' => 'Failed to generate a response: ' . $e->getMessage(),
      ], 500);
    }
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
   * Sends a chat query to the AI provider and returns the raw text response.
   *
   * @param string $systemPrompt
   *   The system-level instruction prompt.
   * @param string $userPrompt
   *   The user-facing prompt containing page content and the question.
   * @param string|null $providerId
   *   The AI provider machine name. If NULL the default provider is resolved.
   *
   * @return string
   *   The raw text response from the AI provider.
   *
   * @throws \RuntimeException
   *   When no chat provider is available or the provider call fails.
   */
  private function sendAiQuery(string $systemPrompt, string $userPrompt, ?string $providerId): string {
    if (!$this->aiProviderManager->hasProvidersForOperationType('chat')) {
      throw new \RuntimeException('No AI chat provider is configured.');
    }

    // Resolve provider + model if none was supplied by the client.
    if (empty($providerId) || $providerId === 'default') {
      $central    = $this->aiProviderManager->getDefaultProviderForOperationType('content_audit')
        ?? $this->aiProviderManager->getDefaultProviderForOperationType('chat');
      $providerId = $central['provider_id'] ?? '';
      $modelId    = $central['model_id']    ?? '';
    }
    else {
      // Look up the model that was configured alongside this provider.
      $modelId = '';
      foreach ($this->getAvailableProviders() as $p) {
        if ($p['id'] === $providerId) {
          $modelId = $p['model'] ?? '';
          break;
        }
      }
    }

    if (empty($providerId)) {
      throw new \RuntimeException('Could not resolve an AI provider ID.');
    }

    $chatInput = new ChatInput([
      new ChatMessage('system', $systemPrompt),
      new ChatMessage('user', $userPrompt),
    ]);

    $proxy  = $this->aiProviderManager->createInstance($providerId);
    $output = $proxy->chat($chatInput, $modelId, ['ai_content_audit', 'preview']);

    return $output->getNormalized()->getText();
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
