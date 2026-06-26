<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit_scoring\Attribute\AuditCheck;
use Drupal\ai_content_audit_scoring\ValueObject\TechnicalAuditResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Checks for local/dev settings files in sites/default/.
 */
#[AuditCheck(
  id: 'fs_dev_settings',
  label: new TranslatableMarkup('Dev Settings Files'),
  description: new TranslatableMarkup('Checks for local/dev settings files like settings.local.php or settings.ddev.php in sites/default/.'),
  scope: 'site',
  category: 'Security',
)]
class DevSettingsFilesCheck extends FilesystemCheckBase {

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $devFiles = [
      'settings.local.php',
      'settings.ddev.php',
      'settings.lando.php',
      'local.services.yml',
      'development.services.yml',
    ];

    $found = [];
    foreach ($devFiles as $filename) {
      $path = $this->safePath('sites/default/' . $filename);
      if ($path !== NULL && file_exists($path)) {
        $found[] = $filename;
      }
    }

    if (empty($found)) {
      return $this->pass(
        'No development-environment settings files were found in sites/default/.',
        'None found',
        'None present',
        ['files_found' => []],
      );
    }

    return $this->warning(
      'Development-environment settings files were found in sites/default/. Ensure these do not override security-sensitive settings in production.',
      implode(', ', $found),
      'None present',
      ['files_found' => $found],
    );
  }

}
