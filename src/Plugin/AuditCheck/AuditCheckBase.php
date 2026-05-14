<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck;

use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Plugin\PluginBase;
use Drupal\node\NodeInterface;

/**
 * Abstract base class for AuditCheck plugins.
 *
 * Provides default implementations of AuditCheckInterface using the plugin
 * definition populated from the #[AuditCheck] attribute, plus protected
 * shortcut factory methods that pre-fill the check ID and label into every
 * TechnicalAuditResult.
 *
 * Concrete check plugins should extend this class and implement run().
 *
 * @see \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckInterface
 * @see \Drupal\ai_content_audit\Attribute\AuditCheck
 * @see \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
 */
abstract class AuditCheckBase extends PluginBase implements AuditCheckInterface {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return (string) $this->pluginDefinition['id'];
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    $label = $this->pluginDefinition['label'];
    return $label instanceof \Stringable ? (string) $label : (string) $label;
  }

  /**
   * {@inheritdoc}
   */
  public function getCategory(): string {
    return (string) ($this->pluginDefinition['category'] ?? 'General');
  }

  /**
   * {@inheritdoc}
   *
   * 'site'-scoped checks apply in both node and non-node contexts.
   * 'node'-scoped checks apply only when a node is provided.
   */
  public function applies(?NodeInterface $node): bool {
    $scope = (string) ($this->pluginDefinition['scope'] ?? 'site');
    if ($scope === 'node') {
      return $node !== NULL;
    }
    // 'site' scope — always applicable.
    return TRUE;
  }

  // ---------------------------------------------------------------------------
  // Protected result factory helpers
  // ---------------------------------------------------------------------------

  /**
   * Creates a passing TechnicalAuditResult for this check.
   *
   * @param string $description
   *   Human-readable description of the check outcome.
   * @param string|null $currentContent
   *   The current content found during the check.
   * @param string|null $recommendedContent
   *   The recommended content (for copy-to-clipboard UX).
   * @param array $details
   *   Additional key-value details to surface in the report.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   */
  protected function pass(
    string $description,
    ?string $currentContent = NULL,
    ?string $recommendedContent = NULL,
    array $details = [],
  ): TechnicalAuditResult {
    return new TechnicalAuditResult(
      check: $this->getId(),
      label: $this->getLabel(),
      status: 'pass',
      currentContent: $currentContent,
      recommendedContent: $recommendedContent,
      description: $description,
      details: $details,
    );
  }

  /**
   * Creates a failing TechnicalAuditResult for this check.
   *
   * @param string $description
   *   Human-readable description of the check outcome.
   * @param string|null $currentContent
   *   The current content found during the check.
   * @param string|null $recommendedContent
   *   The recommended content (for copy-to-clipboard UX).
   * @param array $details
   *   Additional key-value details to surface in the report.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   */
  protected function fail(
    string $description,
    ?string $currentContent = NULL,
    ?string $recommendedContent = NULL,
    array $details = [],
  ): TechnicalAuditResult {
    return new TechnicalAuditResult(
      check: $this->getId(),
      label: $this->getLabel(),
      status: 'fail',
      currentContent: $currentContent,
      recommendedContent: $recommendedContent,
      description: $description,
      details: $details,
    );
  }

  /**
   * Creates a warning TechnicalAuditResult for this check.
   *
   * @param string $description
   *   Human-readable description of the check outcome.
   * @param string|null $currentContent
   *   The current content found during the check.
   * @param string|null $recommendedContent
   *   The recommended content (for copy-to-clipboard UX).
   * @param array $details
   *   Additional key-value details to surface in the report.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   */
  protected function warning(
    string $description,
    ?string $currentContent = NULL,
    ?string $recommendedContent = NULL,
    array $details = [],
  ): TechnicalAuditResult {
    return new TechnicalAuditResult(
      check: $this->getId(),
      label: $this->getLabel(),
      status: 'warning',
      currentContent: $currentContent,
      recommendedContent: $recommendedContent,
      description: $description,
      details: $details,
    );
  }

  /**
   * Creates an informational TechnicalAuditResult for this check.
   *
   * @param string $description
   *   Human-readable description of the check outcome.
   * @param string|null $currentContent
   *   The current content found during the check.
   * @param string|null $recommendedContent
   *   The recommended content (for copy-to-clipboard UX).
   * @param array $details
   *   Additional key-value details to surface in the report.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   */
  protected function info(
    string $description,
    ?string $currentContent = NULL,
    ?string $recommendedContent = NULL,
    array $details = [],
  ): TechnicalAuditResult {
    return new TechnicalAuditResult(
      check: $this->getId(),
      label: $this->getLabel(),
      status: 'info',
      currentContent: $currentContent,
      recommendedContent: $recommendedContent,
      description: $description,
      details: $details,
    );
  }

}
