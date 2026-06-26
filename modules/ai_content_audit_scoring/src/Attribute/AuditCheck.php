<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the AuditCheck attribute for plugin discovery.
 *
 * Each audit check plugin must be annotated with this attribute.
 *
 * @code
 * #[AuditCheck(
 *   id: 'robots_txt',
 *   label: new TranslatableMarkup('robots.txt'),
 *   description: new TranslatableMarkup('Checks robots.txt for AI-friendly directives.'),
 *   scope: 'site',
 *   category: 'AI Signals',
 * )]
 * @endcode
 *
 * @see \Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckInterface
 * @see \Drupal\ai_content_audit_scoring\Plugin\Manager\AuditCheckManager
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AuditCheck extends AttributeBase {

  /**
   * Constructs a new AuditCheck attribute instance.
   *
   * @param string $id
   *   The plugin machine name (e.g. 'robots_txt', 'schema_markup').
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   Human-readable label shown in the audit report.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   Brief description of what the check verifies.
   * @param string $scope
   *   Either 'site' (cacheable, no node required) or 'node' (per-node).
   *   Defaults to 'site'.
   * @param string $category
   *   Grouping category for display (e.g. 'Security', 'AI Signals',
   *   'Filesystem'). Defaults to 'General'.
   * @param bool $enabled
   *   Whether this check is enabled by default. Defaults to TRUE.
   * @param class-string|null $deriver
   *   (optional) The deriver class for derived plugins.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly TranslatableMarkup $description,
    public readonly string $scope = 'site',
    public readonly string $category = 'General',
    public readonly bool $enabled = TRUE,
    public readonly ?string $deriver = NULL,
  ) {}

}
