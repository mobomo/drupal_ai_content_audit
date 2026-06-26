<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit_scoring\Attribute\AuditCheck;
use Drupal\ai_content_audit_scoring\ValueObject\TechnicalAuditResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Checks .htaccess exists in webroot and contains the RewriteEngine directive.
 */
#[AuditCheck(
  id: 'fs_htaccess',
  label: new TranslatableMarkup('.htaccess Presence'),
  description: new TranslatableMarkup('Checks .htaccess exists in webroot and contains the RewriteEngine directive.'),
  scope: 'site',
  category: 'Security',
)]
class HtaccessPresenceCheck extends FilesystemCheckBase {

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $path = $this->safePath('.htaccess');

    if ($path === NULL || !file_exists($path)) {
      return $this->fail(
        '.htaccess is missing from the webroot. Apache installations require it to enforce security rules and clean URLs.',
        'Missing',
        'Present and non-empty',
        ['file_exists' => FALSE, 'file_size_bytes' => 0, 'has_rewrite_engine' => FALSE],
      );
    }

    $fileSize = filesize($path);
    if ($fileSize === FALSE) {
      $fileSize = 0;
    }

    if ($fileSize === 0) {
      return $this->warning(
        '.htaccess exists but is empty. Security rules and clean URL rewrites will not be applied.',
        'Present but empty',
        'Present and non-empty',
        ['file_exists' => TRUE, 'file_size_bytes' => 0, 'has_rewrite_engine' => FALSE],
      );
    }

    // Read up to 64 KB to check for RewriteEngine directive.
    $sample = file_get_contents($path, length: 65536);
    $hasRewrite = $sample !== FALSE && stripos($sample, 'RewriteEngine') !== FALSE;

    return $this->pass(
      '.htaccess is present and non-empty.',
      'Present',
      'Present and non-empty',
      [
        'file_exists' => TRUE,
        'file_size_bytes' => $fileSize,
        'has_rewrite_engine' => $hasRewrite,
      ],
    );
  }

}
