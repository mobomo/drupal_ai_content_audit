<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\AiroPanel\Tab;

use Drupal\ai_content_audit\AiroPanel\AiroPanelTabInterface;
use Drupal\ai_content_audit_scoring\Service\AiroPanelTabBuilder;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Provides the AIRO action items tab.
 */
final class ActionItemsTab implements AiroPanelTabInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly AiroPanelTabBuilder $tabBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'action-items-tab';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return $this->t('Action Items');
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 20;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node, bool $pageSkin = FALSE): bool {
    return !$pageSkin;
  }

  /**
   * {@inheritdoc}
   */
  public function build(NodeInterface $node, bool $pageSkin = FALSE): array {
    return $this->tabBuilder->buildActionItemsTab($node);
  }

}
