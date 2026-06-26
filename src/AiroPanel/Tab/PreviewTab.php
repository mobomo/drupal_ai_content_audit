<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\AiroPanel\Tab;

use Drupal\ai_content_audit\AiroPanel\AiroPanelTabInterface;
use Drupal\ai_content_audit\Service\AiroPreviewTabBuilder;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Provides the AIRO preview chat tab.
 */
final class PreviewTab implements AiroPanelTabInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly AiroPreviewTabBuilder $tabBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'preview-tab';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return $this->t('AI Preview');
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node, bool $pageSkin = FALSE): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function build(NodeInterface $node, bool $pageSkin = FALSE): array {
    return $this->tabBuilder->build($node, $pageSkin);
  }

}
