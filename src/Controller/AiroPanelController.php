<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Controller;

use Drupal\ai_content_audit\Entity\AiContentAssessment;
use Drupal\ai_content_audit\Enum\RenderMode;
use Drupal\ai_content_audit\Repository\AiContentAssessmentRepository;
use Drupal\ai_content_audit\Service\AiAssessmentService;
use Drupal\ai_content_audit\Service\AiroActionItemCommand;
use Drupal\ai_content_audit\Service\AiroInlineScoreWidgetBuilder;
use Drupal\ai_content_audit\Service\AiroNodeRevisionResolver;
use Drupal\ai_content_audit\Service\AiroPanelTabBuilder;
use Drupal\ai_content_audit\Service\AiroPreviewChat;
use Drupal\ai_content_audit\Service\ProviderModelChoices;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenOffCanvasDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for the AIRO off-canvas analysis panel.
 */
class AiroPanelController extends ControllerBase {

  public function __construct(
    protected AiAssessmentService $assessmentService,
    protected RendererInterface $renderer,
    protected RequestStack $requestStack,
    protected AiContentAssessmentRepository $assessmentRepository,
    protected AiroPanelTabBuilder $tabBuilder,
    protected AiroNodeRevisionResolver $revisionResolver,
    protected AiroPreviewChat $previewChat,
    protected AiroActionItemCommand $actionItemCommand,
    protected AiroInlineScoreWidgetBuilder $inlineScoreWidgetBuilder,
    protected ProviderModelChoices $providerModelChoices,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_content_audit.assessment_service'),
      $container->get('renderer'),
      $container->get('request_stack'),
      $container->get('ai_content_audit.assessment_repository'),
      $container->get('ai_content_audit.airo_panel_tab_builder'),
      $container->get('ai_content_audit.airo_node_revision_resolver'),
      $container->get('ai_content_audit.airo_preview_chat'),
      $container->get('ai_content_audit.airo_action_item_command'),
      $container->get('ai_content_audit.airo_inline_score_widget_builder'),
      $container->get('ai_content_audit.provider_model_choices'),
    );
  }

  /**
   * Opens the AIRO analysis panel in the off-canvas dialog.
   */
  public function openPanel(NodeInterface $node): AjaxResponse {
    $assessment = $this->assessmentRepository->getLatestForNode((int) $node->id());
    $paneBuilds = $this->tabBuilder->buildTabPanes($node);

    $tabPanesHtml = [];
    $mergedAttached = ['library' => ['ai_content_audit/airo-panel']];
    foreach ($paneBuilds as $tabId => $paneBuild) {
      $tabPanesHtml[$tabId] = (string) $this->renderer->renderRoot($paneBuild);
      if (!empty($paneBuild['#attached'])) {
        $mergedAttached = array_merge_recursive($mergedAttached, $paneBuild['#attached']);
      }
    }

    $build = [
      '#theme' => 'ai_airo_panel',
      '#node_id' => $node->id(),
      '#revision_id' => (int) $node->getRevisionId(),
      '#score' => $assessment?->getScore(),
      '#node_title' => $node->getTitle(),
      '#is_analyzing' => FALSE,
      '#active_tab' => 'preview-tab',
      '#tab_panes' => $tabPanesHtml,
      '#attached' => $mergedAttached,
      '#assess_url' => Url::fromRoute('ai_content_audit.panel.assess', [
        'node' => $node->id(),
      ])->toString(),
      '#full_report_url' => $this->buildFullReportUrl($assessment),
    ];

    $html = $this->renderer->renderRoot($build);

    $response = new AjaxResponse();
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
    $response->setAttachments($mergedAttached);

    return $response;
  }

  /**
   * Triggers an AI assessment for the given node via AJAX.
   */
  public function assessNode(NodeInterface $node): JsonResponse {
    try {
      $node = $this->revisionResolver->resolveFromRequestBody($node, $this->getJsonRequestBody());
      $result = $this->assessmentService->assessNode($node, [
        'render_mode' => RenderMode::Html->value,
      ]);

      if (!$result['success']) {
        $this->getLogger('ai_content_audit')->error('Assessment failed: @message', [
          '@message' => $result['error'] ?? 'Unknown error',
        ]);
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('Assessment failed: @error', [
            '@error' => $result['error'] ?? 'Unknown error',
          ]),
        ], 500);
      }

      $assessment = $this->assessmentRepository->getLatestForNode((int) $node->id());
      return new JsonResponse([
        'status' => 'complete',
        'score' => $assessment?->get('score')->value,
        'assessment_id' => $assessment?->id(),
      ]);
    }
    catch (AccessDeniedHttpException $e) {
      throw $e;
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
   * Re-renders the inline score widget and returns a Drupal AJAX command.
   */
  public function refreshWidget(NodeInterface $node): AjaxResponse {
    $widgetBuild = $this->inlineScoreWidgetBuilder->build($node);
    $html = (string) $this->renderer->renderRoot($widgetBuild);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand(
      '.airo-widget[data-node-id="' . $node->id() . '"]',
      $html,
    ));
    $response->setAttachments($widgetBuild['#attached'] ?? []);
    return $response;
  }

  /**
   * Returns the current assessment status for polling.
   */
  public function assessmentStatus(NodeInterface $node): JsonResponse {
    $assessment = $this->assessmentRepository->getLatestForNode((int) $node->id());
    if ($assessment !== NULL) {
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
   * Toggles the completion state of an action item.
   */
  public function toggleActionItem(NodeInterface $node, string $item_id): JsonResponse {
    $body = $this->getJsonRequestBody();
    $result = $this->actionItemCommand->toggle($node, $item_id, !empty($body['completed']));
    return new JsonResponse($result['payload'], $result['status_code']);
  }

  /**
   * Handles multi-provider preview query POST.
   */
  public function submitPreviewQuery(NodeInterface $node): JsonResponse {
    $body = $this->getJsonRequestBody();
    $node = $this->revisionResolver->resolveFromRequestBody($node, $body);

    return $this->previewChat->submit($node, $body);
  }

  /**
   * Returns configured provider and model choices as JSON.
   */
  public function listAvailableModels(): JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    $opType = $request?->query->get('op_type', 'chat');
    $choices = $this->providerModelChoices->forOperationType((string) $opType);

    $cacheability = (new CacheableMetadata())->setCacheMaxAge(0);
    $response = new CacheableJsonResponse(['choices' => $choices]);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Decodes the JSON request body.
   *
   * @return array<string, mixed>
   *   Decoded request body, or an empty array for invalid/non-object JSON.
   */
  private function getJsonRequestBody(): array {
    $body = json_decode($this->requestStack->getCurrentRequest()?->getContent() ?: '{}', TRUE);
    return is_array($body) ? $body : [];
  }

  /**
   * Builds the full report URL for an assessment when available.
   */
  private function buildFullReportUrl(?AiContentAssessment $assessment): ?string {
    if ($assessment === NULL) {
      return NULL;
    }

    return Url::fromRoute('ai_content_audit.assessment.report', [
      'ai_content_assessment' => $assessment->id(),
    ])->toString();
  }

}
