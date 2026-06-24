<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Service;

use Drupal\ai_content_audit\Plugin\Manager\AuditCheckManager;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs filesystem-scoped audit checks via the AuditCheck plugin system.
 *
 * Individual checks live under Plugin/AuditCheck/Filesystem (ID prefix fs_).
 * Path traversal guards and scan limits are enforced in FilesystemCheckBase.
 */
final class FilesystemAuditService {

  protected const CACHE_TTL = 900;
  protected const CACHE_ID = 'ai_content_audit_scoring:filesystem_audit';

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly CacheBackendInterface $cacheData,
    private readonly AuditCheckManager $auditCheckManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Runs all filesystem audit checks and returns an array of results.
   *
   * Results are cached for CACHE_TTL seconds unless $force_refresh is TRUE.
   *
   * @param bool $force_refresh
   *   When TRUE, bypass the cache and re-run every check.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult[]
   *   Indexed array of audit results, one per check.
   */
  public function runAllChecks(bool $force_refresh = FALSE): array {
    if (!$force_refresh) {
      $cached = $this->cacheData->get(self::CACHE_ID);
      if ($cached !== FALSE && isset($cached->data)) {
        return $cached->data;
      }
    }

    $results = [];

    // Only run filesystem-scoped checks — identified by the 'fs_' plugin ID
    // prefix. Technical checks (no prefix) belong to TechnicalAuditService.
    foreach ($this->auditCheckManager->getDefinitions() as $id => $definition) {
      if (!str_starts_with($id, 'fs_')) {
        continue;
      }

      try {
        /** @var \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckInterface $check */
        $check = $this->auditCheckManager->createInstance($id);

        if (!$check->applies(NULL)) {
          continue;
        }

        $results[] = $check->run(NULL);
      }
      catch (\Throwable $e) {
        $this->logger->error('FilesystemAuditService check "@id" failed: @msg', [
          '@id' => $id,
          '@msg' => $e->getMessage(),
        ]);
        $results[] = new TechnicalAuditResult(
          check: $id,
          label: 'Check Error',
          status: 'error',
          currentContent: NULL,
          recommendedContent: NULL,
          description: 'An unexpected error occurred while running this check.',
        );
      }
    }

    $this->cacheData->set(
      self::CACHE_ID,
      $results,
      $this->time->getRequestTime() + self::CACHE_TTL,
    );

    return $results;
  }

  /**
   * Invalidates the filesystem audit cache entry.
   */
  public function invalidateCache(): void {
    $this->cacheData->delete(self::CACHE_ID);
  }

}
