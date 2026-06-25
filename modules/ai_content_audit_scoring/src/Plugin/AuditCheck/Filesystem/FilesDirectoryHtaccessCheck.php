<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit_scoring\Attribute\AuditCheck;
use Drupal\ai_content_audit_scoring\ValueObject\TechnicalAuditResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Checks sites/default/files/.htaccess blocks PHP execution and direct access.
 */
#[AuditCheck(
  id: 'fs_files_htaccess',
  label: new TranslatableMarkup('Files Directory .htaccess'),
  description: new TranslatableMarkup('Checks sites/default/files/.htaccess exists and blocks PHP execution and direct access.'),
  scope: 'site',
  category: 'Security',
)]
class FilesDirectoryHtaccessCheck extends FilesystemCheckBase {

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $path = $this->safePath('sites/default/files/.htaccess');

    if ($path === NULL || !file_exists($path)) {
      return $this->fail(
        'sites/default/files/.htaccess is missing. Without it, PHP scripts uploaded to this directory may be executed.',
        'Missing',
        'Present with PHP-blocking directives',
        ['has_php_handler_block' => FALSE, 'has_direct_access_deny' => FALSE],
      );
    }

    $raw = file_get_contents($path, length: 65536);
    $hasPhpBlock = $raw !== FALSE && (bool) preg_match('/php_flag\s+engine\s+off/i', $raw);
    $hasAccessDeny = $raw !== FALSE && (bool) preg_match('/(Require\s+all\s+denied|deny\s+from\s+all)/i', $raw);
    unset($raw);

    $details = [
      'has_php_handler_block' => $hasPhpBlock,
      'has_direct_access_deny' => $hasAccessDeny,
    ];

    if ($hasPhpBlock || $hasAccessDeny) {
      return $this->pass(
        'sites/default/files/.htaccess is present and contains PHP-blocking directives.',
        'Present with PHP-blocking directives',
        'Present with PHP-blocking directives',
        $details,
      );
    }

    return $this->warning(
      'sites/default/files/.htaccess exists but does not contain expected PHP-blocking directives. Uploaded PHP scripts may be executable.',
      'Present but lacks PHP-blocking',
      'Present with PHP-blocking directives',
      $details,
    );
  }

}
