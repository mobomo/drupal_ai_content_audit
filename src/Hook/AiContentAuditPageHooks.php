<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Page attachments (Gin shim when AIRO libraries are present).
 */
final class AiContentAuditPageHooks {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  #[Hook('page_attachments_alter')]
  public function pageAttachmentsAlter(array &$attachments): void {
    $system_theme_config = $this->configFactory->get('system.theme');
    $attachments['#cache']['tags'] = Cache::mergeTags(
      $attachments['#cache']['tags'] ?? [],
      $system_theme_config->getCacheTags()
    );

    if ($system_theme_config->get('admin') !== 'gin') {
      return;
    }

    $attached_libraries = $attachments['#attached']['library'] ?? [];
    $airo_present = array_filter($attached_libraries, static fn(string $lib): bool =>
      str_starts_with($lib, 'ai_content_audit/airo-panel') ||
      str_starts_with($lib, 'ai_content_audit/assessment-report') ||
      str_starts_with($lib, 'ai_content_audit/inline-widget')
    );

    if (empty($airo_present)) {
      return;
    }

    $attachments['#attached']['library'][] = 'ai_content_audit/airo-panel-gin-shim';
  }

}
