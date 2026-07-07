<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\AiroPanel\Tab;

use Drupal\ai_content_audit\AiroPanel\AiroPanelTabInterface;
use Drupal\ai_content_audit_scoring\Service\AiroCheckpointsViewModelBuilder;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Provides the page-skin Readiness Checkpoints tab.
 */
final class CheckpointsTab implements AiroPanelTabInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly AiroCheckpointsViewModelBuilder $viewModelBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'checkpoints';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return $this->t('Checkpoints');
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 10;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node, bool $pageSkin = FALSE): bool {
    return $pageSkin;
  }

  /**
   * {@inheritdoc}
   */
  public function build(NodeInterface $node, bool $pageSkin = FALSE): array {
    $viewModel = $this->viewModelBuilder->build($node);

    return [
      '#theme' => 'ai_checkpoints_tab',
      '#has_assessment' => $viewModel['has_assessment'],
      '#summary_items' => $viewModel['summary_items'],
      '#sections' => $viewModel['sections'],
      '#last_check_label' => $viewModel['last_check_label'],
      '#node_id' => $viewModel['node_id'],
      '#revision_id' => $viewModel['revision_id'],
      '#attached' => [
        'library' => [
          'ai_content_audit_scoring/checkpoints-tab',
        ],
      ],
      '#cache' => [
        'tags' => $viewModel['cache_tags'],
        'contexts' => $viewModel['cache_contexts'],
      ],
    ];
  }

}
