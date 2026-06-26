<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\AiroPanel\Tab;

use Drupal\ai_content_audit\AiroPanel\AiroPanelTabInterface;
use Drupal\ai_content_audit_scoring\Service\AiroPanelTabBuilder;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Provides the AIRO technical audit tab.
 */
final class TechnicalAuditTab implements AiroPanelTabInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly AiroPanelTabBuilder $tabBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'technical-audit-tab';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return $this->t('Technical Audit');
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 30;
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
    return $this->tabBuilder->buildTechnicalAuditTab($node);
  }

}
