<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai_content_audit\Controller\AiroPanelController;
use Drupal\ai_content_audit\Plugin\Manager\AuditCheckManager;
use Drupal\ai_content_audit\Repository\AiContentAssessmentRepository;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Builds the AIRO analysis side panel (score, checks, tabs) for a node.
 */
final class AiroAnalysisPanelBuilder {

  use StringTranslationTrait;

  /**
   * Node-scoped technical checks in the overlay (score tab filter).
   */
  private const NODE_TECHNICAL_CHECKS = [
    'canonical_url',
    'schema_markup',
    'entity_relationships',
    'date_meta_tags',
  ];

  public function __construct(
    protected AiContentAssessmentRepository $assessmentRepository,
    protected AiroPanelController $airoPanelController,
    protected AuditCheckManager $auditCheckManager,
    protected TechnicalAuditService $technicalAuditService,
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

    $build['status'] = $this->buildStatusSection($node);
    $available = $this->buildAvailableChecksSection($node);
    if ($available !== NULL) {
      $build['available_checks'] = $available;
    }
    $results = $this->buildPluginResultsSection($node);
    if ($results !== NULL) {
      $build['plugin_results'] = $results;
    }
    $recommendations = $this->buildRecommendationsSection($node);
    if ($recommendations !== NULL) {
      $build['recommendations'] = $recommendations;
    }
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
    $preview = $this->airoPanelController->buildPreviewTab($node, $pageSkin);
    if ($pageSkin) {
      return ['preview-tab' => $preview];
    }

    $assessment = $this->assessmentRepository->getLatestForNode((int) $node->id());

    return [
      'preview-tab' => $preview,
      'score-tab' => $this->airoPanelController->buildScoreTab($node, $assessment),
      'action-items-tab' => $this->airoPanelController->buildActionItemsTab($node),
      'technical-audit-tab' => $this->airoPanelController->buildTechnicalAuditTab($node),
    ];
  }

  /**
   * Assess and full-report URLs for the node.
   *
   * @return array{assess_url: string, full_report_url: string|null}
   *   URLs for running an assessment and viewing the full report.
   */
  public function buildActionUrls(NodeInterface $node): array {
    $nodeId = (int) $node->id();
    $assessment = $this->assessmentRepository->getLatestForNode($nodeId);

    $assessUrl = Url::fromRoute('ai_content_audit.panel.assess', ['node' => $nodeId])->toString();
    $fullReportUrl = $assessment
      ? Url::fromRoute('ai_content_audit.assessment.report', [
        'ai_content_assessment' => $assessment->id(),
      ])->toString()
      : NULL;

    return [
      'assess_url' => $assessUrl,
      'full_report_url' => $fullReportUrl,
    ];
  }

  /**
   * Inline score widget (status section).
   */
  protected function buildStatusSection(NodeInterface $node): array {
    $nodeId = (int) $node->id();
    $assessment = $this->assessmentRepository->getLatestForNode($nodeId);
    $score = $assessment?->getScore();
    $hasAssessment = $assessment !== NULL;

    $highPriorityCount = 0;
    if ($assessment && !$assessment->get('action_items')->isEmpty()) {
      $items = $assessment->getActionItems() ?? [];
      foreach ($items as $item) {
        if (($item['priority'] ?? '') === 'high') {
          $highPriorityCount++;
        }
      }
    }

    $urls = $this->buildActionUrls($node);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['airo-analysis-panel__section', 'airo-analysis-panel__status']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Status'),
        '#attributes' => ['class' => ['airo-analysis-panel__heading']],
      ],
      'widget' => [
        '#theme' => 'ai_inline_score_widget',
        '#score' => $score,
        '#is_analyzing' => FALSE,
        '#has_assessment' => $hasAssessment,
        '#high_priority_count' => $highPriorityCount,
        '#node_id' => $nodeId,
        '#revision_id' => (int) $node->getRevisionId(),
        '#assess_url' => $urls['assess_url'],
        '#attached' => ['library' => ['ai_content_audit/inline-widget']],
      ],
    ];
  }

  /**
   * Lists enabled audit check plugins applicable to this node.
   */
  protected function buildAvailableChecksSection(NodeInterface $node): ?array {
    $items = [];
    foreach ($this->auditCheckManager->getEnabledCheckIds() as $pluginId) {
      try {
        /** @var \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckInterface $plugin */
        $plugin = $this->auditCheckManager->createInstance($pluginId);
        if (!$plugin->applies($node)) {
          continue;
        }
        $definition = $this->auditCheckManager->getDefinition($pluginId);
        $items[] = ($definition['label'] ?? $plugin->getLabel())
          . ' (' . ($definition['category'] ?? $plugin->getCategory()) . ')';
      }
      catch (\Throwable) {
        continue;
      }
    }

    if ($items === []) {
      return NULL;
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['airo-analysis-panel__section', 'airo-analysis-panel__available-checks']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Available checks'),
        '#attributes' => ['class' => ['airo-analysis-panel__heading']],
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['airo-analysis-panel__check-list']],
      ],
    ];
  }

  /**
   * Node-scoped audit check results from plugins.
   */
  protected function buildPluginResultsSection(NodeInterface $node): ?array {
    $allResults = $this->technicalAuditService->runAllChecks($node, FALSE);
    $rows = [];
    foreach ($allResults as $result) {
      if (!in_array($result->check, self::NODE_TECHNICAL_CHECKS, TRUE)) {
        continue;
      }
      $data = $result->toArray();
      $rows[] = $data['label'] . ': ' . $data['status'];
    }

    if ($rows === []) {
      return NULL;
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['airo-analysis-panel__section', 'airo-analysis-panel__plugin-results']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Check results'),
        '#attributes' => ['class' => ['airo-analysis-panel__heading']],
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $rows,
      ],
    ];
  }

  /**
   * Action item titles from the latest assessment (recommendations).
   */
  protected function buildRecommendationsSection(NodeInterface $node): ?array {
    $assessment = $this->assessmentRepository->getLatestForNode((int) $node->id());
    if (!$assessment) {
      return NULL;
    }

    $actionItems = $assessment->getActionItems() ?? [];
    if ($actionItems === []) {
      return NULL;
    }

    $titles = [];
    foreach ($actionItems as $item) {
      $title = trim((string) ($item['title'] ?? ''));
      if ($title !== '') {
        $priority = $item['priority'] ?? 'low';
        $titles[] = '[' . $priority . '] ' . $title;
      }
    }

    if ($titles === []) {
      return NULL;
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['airo-analysis-panel__section', 'airo-analysis-panel__recommendations']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Recommendations'),
        '#attributes' => ['class' => ['airo-analysis-panel__heading']],
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $titles,
      ],
    ];
  }

  /**
   * Accordion panel theme (preview, score, action items, technical).
   */
  protected function buildAiroAccordionPanel(NodeInterface $node, string $variant = 'accordion'): array {
    $nodeId = (int) $node->id();
    $assessment = $this->assessmentRepository->getLatestForNode($nodeId);
    $urls = $this->buildActionUrls($node);
    $pageSkin = $variant === 'page';

    return [
      '#theme' => 'ai_airo_accordion_item',
      '#node_id' => $nodeId,
      '#revision_id' => (int) $node->getRevisionId(),
      '#score' => $assessment?->getScore(),
      '#node_title' => $node->getTitle(),
      '#is_analyzing' => FALSE,
      '#active_tab' => 'preview-tab',
      '#use_page_skin' => $pageSkin,
      '#close_url' => $pageSkin
        ? Url::fromRoute('entity.node.edit_form', ['node' => $nodeId])->toString()
        : NULL,
      '#logo_url' => $pageSkin ? $this->getAiroLogoUrl() : NULL,
      '#tab_panes' => $this->buildTabPanes($node, $pageSkin),
      '#assess_url' => $urls['assess_url'],
      '#full_report_url' => $urls['full_report_url'],
    ];
  }

  /**
   * Main AIRO tabbed workspace (preview, score, action items, technical).
   */
  protected function buildAiroWorkspaceSection(NodeInterface $node): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['airo-analysis-panel__section', 'airo-analysis-panel__workspace']],
      'panel' => $this->buildAiroAccordionPanel($node),
    ];
  }

}
