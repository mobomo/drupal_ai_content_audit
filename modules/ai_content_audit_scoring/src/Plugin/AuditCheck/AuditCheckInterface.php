<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Plugin\AuditCheck;

use Drupal\ai_content_audit_scoring\ValueObject\TechnicalAuditResult;
use Drupal\node\NodeInterface;

/**
 * Interface for AuditCheck plugins.
 *
 * Every audit check plugin must implement this interface. Checks are
 * categorised by scope:
 *  - 'site'  — site-wide checks that do not require a specific node.
 *              These are cacheable and run regardless of context.
 *  - 'node'  — per-node checks that require a NodeInterface to be provided.
 *
 * @see \Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckBase
 * @see \Drupal\ai_content_audit_scoring\Plugin\Manager\AuditCheckManager
 * @see \Drupal\ai_content_audit_scoring\Attribute\AuditCheck
 */
interface AuditCheckInterface {

  /**
   * Returns the plugin machine name.
   *
   * @return string
   *   The plugin ID as defined in the #[AuditCheck] attribute.
   */
  public function getId(): string;

  /**
   * Returns the human-readable label of this check.
   *
   * @return string
   *   The label string.
   */
  public function getLabel(): string;

  /**
   * Returns the category this check belongs to.
   *
   * @return string
   *   The category string (e.g. 'Security', 'AI Signals', 'Filesystem').
   */
  public function getCategory(): string;

  /**
   * Determines whether this check is applicable to the given context.
   *
   * Site-scope checks ('scope' == 'site') return TRUE when $node is NULL,
   * meaning they run in a general site context. Node-scope checks
   * ('scope' == 'node') return TRUE only when a specific $node is provided.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The node to test applicability against, or NULL for site-level context.
   *
   * @return bool
   *   TRUE if this check should run in the current context.
   */
  public function applies(?NodeInterface $node): bool;

  /**
   * Executes the audit check and returns the result.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   Optional node for node-scoped checks. Site-scoped checks ignore this.
   *
   * @return \Drupal\ai_content_audit_scoring\ValueObject\TechnicalAuditResult
   *   The result of the check.
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult;

}
