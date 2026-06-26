<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Controller;

use Drupal\ai_content_audit\Service\AiroNodeRevisionResolver;
use Drupal\ai_content_audit\Service\AiroPanelTabManager;
use Drupal\ai_content_audit\Service\AiroPreviewChat;
use Drupal\ai_content_audit\Service\ProviderModelChoices;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenOffCanvasDialogCommand;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Controller for the AIRO Preview off-canvas panel.
 */
class AiroPanelController extends ControllerBase {

  public function __construct(
    protected RendererInterface $renderer,
    protected RequestStack $requestStack,
    protected AiroPanelTabManager $tabManager,
    protected AiroNodeRevisionResolver $revisionResolver,
    protected AiroPreviewChat $previewChat,
    protected ProviderModelChoices $providerModelChoices,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('renderer'),
      $container->get('request_stack'),
      $container->get('ai_content_audit.airo_panel_tab_manager'),
      $container->get('ai_content_audit.airo_node_revision_resolver'),
      $container->get('ai_content_audit.airo_preview_chat'),
      $container->get('ai_content_audit.provider_model_choices'),
    );
  }

  /**
   * Opens the AIRO Preview panel in the off-canvas dialog.
   */
  public function openPanel(NodeInterface $node): AjaxResponse {
    $paneBuilds = $this->tabManager->buildTabPanes($node);
    $tabDefinitions = $this->tabManager->buildTabDefinitions($node);
    $activeTab = array_key_first($paneBuilds) ?: 'preview-tab';
    $showAssessmentActions = $this->hasAssessmentTabs($paneBuilds);

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
      '#score' => NULL,
      '#node_title' => $node->getTitle(),
      '#is_analyzing' => FALSE,
      '#active_tab' => $activeTab,
      '#tab_definitions' => $tabDefinitions,
      '#tab_panes' => $tabPanesHtml,
      '#show_assessment_actions' => $showAssessmentActions,
      '#attached' => $mergedAttached,
      '#assess_url' => $showAssessmentActions
        ? $this->buildAssessUrl($node)
        : NULL,
      '#full_report_url' => NULL,
    ];

    $html = $this->renderer->renderRoot($build);

    $response = new AjaxResponse();
    $response->addCommand(new OpenOffCanvasDialogCommand(
      (string) $this->t('AIRO Preview: @title', ['@title' => $node->label()]),
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
   * Builds the assessment trigger URL when scoring routes are enabled.
   */
  private function buildAssessUrl(NodeInterface $node): ?string {
    try {
      return Url::fromRoute('ai_content_audit.panel.assess', [
        'node' => $node->id(),
      ])->toString();
    }
    catch (RouteNotFoundException) {
      return NULL;
    }
  }

  /**
   * Whether the active tab set includes assessment-owned tabs.
   *
   * @param array<string, array> $tabPanes
   *   Tab pane render arrays keyed by tab ID.
   */
  private function hasAssessmentTabs(array $tabPanes): bool {
    foreach (['score-tab', 'action-items-tab', 'technical-audit-tab'] as $tabId) {
      if (isset($tabPanes[$tabId])) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
