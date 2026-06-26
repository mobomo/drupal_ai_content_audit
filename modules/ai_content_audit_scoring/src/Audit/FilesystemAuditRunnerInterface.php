<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Audit;

/**
 * Runs filesystem-scoped technical audit checks.
 */
interface FilesystemAuditRunnerInterface {

  /**
   * Runs all filesystem audit checks.
   *
   * @param bool $force_refresh
   *   When TRUE, bypass cached results.
   *
   * @return array<int, \Drupal\ai_content_audit_scoring\ValueObject\TechnicalAuditResult>
   *   Audit results keyed by check order.
   */
  public function runAllChecks(bool $force_refresh = FALSE): array;

}
