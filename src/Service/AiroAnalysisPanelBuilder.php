<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Builds the AIRO Preview side panel for a node.
 */
final class AiroAnalysisPanelBuilder {

  use StringTranslationTrait;

  public function __construct(
    protected AiroPanelTabManager $tabManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Returns the URL for the AIRO logo asset.
   */
  public function getAiroLogoUrl(): string {
    $module_path = $this->moduleHandler->getModule('ai_content_audit')->getPath();
    return Url::fromUri('base:' . $module_path . '/images/airo-logo.svg')->toString();
  }

  /**
   * Builds the full side panel render array.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being analyzed.
   * @param array $options
   *   Options:
   *   - variant: (string) 'page' or 'accordion'. Affects wrapper classes only.
   *
   * @return array
   *   Render array for the analysis panel column.
   */
  public function build(NodeInterface $node, array $options = []): array {
    $options += ['variant' => 'page'];

    // Page tab: same accordion workspace as the node edit sidebar.
    if ($options['variant'] === 'page') {
      $build = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'airo-analysis-panel',
            'airo-analysis-panel--page',
          ],
        ],
        'panel' => $this->buildAiroAccordionPanel($node, 'page'),
        '#attached' => [
          'library' => [
            'ai_content_audit/airo-panel',
            'ai_content_audit/airo-panel-page-skin',
          ],
        ],
      ];
      $this->moduleHandler->alter('airo_analysis_panel', $build, $node);
      return $build;
    }

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'airo-analysis-panel',
          'airo-analysis-panel--' . $options['variant'],
        ],
      ],
    ];

    $build['airo_workspace'] = $this->buildAiroWorkspaceSection($node);

    $this->moduleHandler->alter('airo_analysis_panel', $build, $node);

    $build['#attached']['library'][] = 'ai_content_audit/airo-panel';

    return $build;
  }

  /**
   * Tab panes keyed by tab ID (for accordion / embedded panel themes).
   *
   * @return array<string, array>
   *   Tab pane render arrays keyed by tab ID.
   */
  public function buildTabPanes(NodeInterface $node, bool $pageSkin = FALSE): array {
    return $this->tabManager->buildTabPanes($node, $pageSkin);
  }

  /**
   * Tab definitions for navigation templates.
   *
   * @return array<int, array{id: string, label: \Drupal\Core\StringTranslation\TranslatableMarkup}>
   *   Template-ready tab definitions.
   */
  public function buildTabDefinitions(NodeInterface $node, bool $pageSkin = FALSE): array {
    return $this->tabManager->buildTabDefinitions($node, $pageSkin);
  }

  /**
   * Whether the active tab set includes assessment-owned tabs.
   *
   * @param array<string, array> $tabPanes
   *   Tab pane render arrays keyed by tab ID.
   */
  public function hasAssessmentTabs(array $tabPanes): bool {
    foreach (['score-tab', 'action-items-tab', 'technical-audit-tab'] as $tabId) {
      if (isset($tabPanes[$tabId])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Assess and full-report URLs for the node.
   *
   * @return array{assess_url: string|null, full_report_url: string|null}
   *   URLs for running an assessment and viewing the full report.
   */
  public function buildActionUrls(NodeInterface $node): array {
    $nodeId = (int) $node->id();

    try {
      $assessUrl = Url::fromRoute('ai_content_audit.panel.assess', ['node' => $nodeId])->toString();
    }
    catch (RouteNotFoundException) {
      $assessUrl = NULL;
    }

    return [
      'assess_url' => $assessUrl,
      'full_report_url' => NULL,
    ];
  }

  /**
   * Accordion panel theme.
   */
  protected function buildAiroAccordionPanel(NodeInterface $node, string $variant = 'accordion'): array {
    $nodeId = (int) $node->id();
    $urls = $this->buildActionUrls($node);
    $pageSkin = $variant === 'page';
    $tabPanes = $this->buildTabPanes($node, $pageSkin);
    $tabDefinitions = $this->buildTabDefinitions($node, $pageSkin);
    $activeTab = array_key_first($tabPanes) ?: 'preview-tab';
    $showAssessmentActions = $this->hasAssessmentTabs($tabPanes);

    return [
      '#theme' => 'ai_airo_accordion_item',
      '#node_id' => $nodeId,
      '#revision_id' => (int) $node->getRevisionId(),
      '#score' => NULL,
      '#node_title' => $node->getTitle(),
      '#is_analyzing' => FALSE,
      '#active_tab' => $activeTab,
      '#use_page_skin' => $pageSkin,
      '#close_url' => $pageSkin
        ? Url::fromRoute('entity.node.edit_form', ['node' => $nodeId])->toString()
        : NULL,
      '#logo_url' => $pageSkin ? $this->getAiroLogoUrl() : NULL,
      '#tab_definitions' => $tabDefinitions,
      '#tab_panes' => $tabPanes,
      '#show_assessment_actions' => $showAssessmentActions,
      '#assess_url' => $urls['assess_url'],
      '#full_report_url' => $urls['full_report_url'],
    ];
  }

  /**
   * Main AIRO tabbed workspace.
   */
  protected function buildAiroWorkspaceSection(NodeInterface $node): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['airo-analysis-panel__section', 'airo-analysis-panel__workspace']],
      'panel' => $this->buildAiroAccordionPanel($node),
    ];
  }

}
