<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Controller;

use Drupal\ai_content_audit\Enum\RenderMode;
use Drupal\ai_content_audit_scoring\Repository\AiContentAssessmentRepository;
use Drupal\ai_content_audit_scoring\Service\AiAssessmentService;
use Drupal\ai_content_audit_scoring\Service\AiroActionItemCommand;
use Drupal\ai_content_audit_scoring\Service\AiroInlineScoreWidgetBuilder;
use Drupal\ai_content_audit_scoring\Service\AiroReadinessViewModelBuilder;
use Drupal\ai_content_audit\Service\AiroNodeRevisionResolver;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for AIRO Scoring panel endpoints.
 */
final class ScoringPanelController extends ControllerBase {

  public function __construct(
    private readonly AiAssessmentService $assessmentService,
    private readonly RendererInterface $renderer,
    private readonly RequestStack $requestStack,
    private readonly AiContentAssessmentRepository $assessmentRepository,
    private readonly AiroNodeRevisionResolver $revisionResolver,
    private readonly AiroActionItemCommand $actionItemCommand,
    private readonly AiroInlineScoreWidgetBuilder $inlineScoreWidgetBuilder,
    private readonly AiroReadinessViewModelBuilder $readinessViewModelBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_content_audit_scoring.assessment_service'),
      $container->get('renderer'),
      $container->get('request_stack'),
      $container->get('ai_content_audit_scoring.assessment_repository'),
      $container->get('ai_content_audit.airo_node_revision_resolver'),
      $container->get('ai_content_audit_scoring.airo_action_item_command'),
      $container->get('ai_content_audit_scoring.airo_inline_score_widget_builder'),
      $container->get('ai_content_audit_scoring.airo_readiness_view_model_builder'),
    );
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
   * Re-renders the Readiness Checkpoints widget.
   */
  public function refreshReadinessWidget(NodeInterface $node): AjaxResponse {
    $viewModel = $this->readinessViewModelBuilder->build($node);
    $widgetBuild = [
      '#theme' => 'ai_readiness_checkpoints',
      '#node_id' => $viewModel['node_id'],
      '#revision_id' => $viewModel['revision_id'],
      '#items' => $viewModel['items'],
      '#has_assessment' => $viewModel['has_assessment'],
      '#action_label' => $viewModel['action_label'],
      '#assess_url' => $viewModel['assess_url'],
      '#refresh_url' => $viewModel['refresh_url'],
      '#full_breakdown_enabled' => $viewModel['full_breakdown_enabled'],
      '#last_check_label' => $viewModel['last_check_label'],
      '#attached' => [
        'library' => [
          'ai_content_audit_scoring/readiness-checkpoints',
        ],
      ],
      '#cache' => [
        'tags' => $viewModel['cache_tags'],
        'contexts' => $viewModel['cache_contexts'],
      ],
    ];
    $html = (string) $this->renderer->renderRoot($widgetBuild);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand(
      '.airo-readiness[data-node-id="' . $node->id() . '"]',
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
   * Decodes the JSON request body.
   *
   * @return array<string, mixed>
   *   Decoded request body, or an empty array for invalid/non-object JSON.
   */
  private function getJsonRequestBody(): array {
    $body = json_decode($this->requestStack->getCurrentRequest()?->getContent() ?: '{}', TRUE);
    return is_array($body) ? $body : [];
  }

}
