<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Hook;

use Drupal\ai_content_audit_scoring\Service\AiroReadinessViewModelBuilder;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;

/**
 * Preview-tab alter hooks for AIRO scoring integrations.
 */
final class ScoringPreviewHooks {

  public function __construct(
    private readonly AiroReadinessViewModelBuilder $viewModelBuilder,
  ) {}

  /**
   * Implements hook_airo_preview_tab_alter().
   */
  #[Hook('airo_preview_tab_alter')]
  public function airoPreviewTabAlter(array &$build, NodeInterface $node, bool $page_skin): void {
    if (!$page_skin) {
      return;
    }

    $viewModel = $this->viewModelBuilder->build($node);

    $build['#after_landing_content']['ai_content_audit_scoring_readiness'] = [
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
  }

}
