<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\ValueObject;

/**
 * Immutable value object containing the result of a single technical audit check.
 */
final class TechnicalAuditResult {

  /**
   * Constructs a TechnicalAuditResult.
   *
   * @param string $check
   *   Machine name of the check (e.g., 'robots_txt', 'llms_txt').
   * @param string $label
   *   Human-readable label for this check.
   * @param string $status
   *   One of: 'pass', 'fail', 'warning'.
   * @param string|null $currentContent
   *   The current content found (e.g., robots.txt contents).
   * @param string|null $recommendedContent
   *   The recommended content (for copy-to-clipboard).
   * @param string $description
   *   Human-readable description of what was checked.
   * @param array $details
   *   Additional key-value details.
   */
  public function __construct(
    public readonly string $check,
    public readonly string $label,
    public readonly string $status,
    public readonly ?string $currentContent,
    public readonly ?string $recommendedContent,
    public readonly string $description,
    public readonly array $details = [],
  ) {}

  /**
   * Converts to array for template rendering.
   */
  public function toArray(): array {
    return [
      'check' => $this->check,
      'label' => $this->label,
      'status' => $this->status,
      'current_content' => $this->currentContent,
      'recommended_content' => $this->recommendedContent,
      'description' => $this->description,
      'details' => $this->details,
    ];
  }

}
