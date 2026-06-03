<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Checks settings.php defines trusted_host_patterns.
 */
#[AuditCheck(
  id: 'fs_trusted_hosts',
  label: new TranslatableMarkup('Trusted Host Patterns'),
  description: new TranslatableMarkup('Checks settings.php defines trusted_host_patterns.'),
  scope: 'site',
  category: 'Security',
)]
class TrustedHostPatternsCheck extends FilesystemCheckBase {

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $path = $this->safePath('sites/default/settings.php');

    if ($path === NULL) {
      return $this->fail(
        'settings.php could not be located inside the Drupal root; trusted_host_patterns cannot be verified.',
        'Not found',
        'Configured',
        ['trusted_hosts_configured' => FALSE],
      );
    }

    $raw = file_get_contents($path, length: 65536);
    $found = $raw !== FALSE && (bool) preg_match('/\$settings\[.trusted_host_patterns.\]/m', $raw);
    unset($raw);

    if ($found) {
      return $this->pass(
        'trusted_host_patterns is defined in settings.php.',
        'Configured',
        'Configured',
        ['trusted_hosts_configured' => TRUE],
      );
    }

    return $this->fail(
      'trusted_host_patterns is not defined in settings.php. Without it, Drupal is vulnerable to HTTP Host header injection attacks.',
      'Not configured',
      'Configured',
      ['trusted_hosts_configured' => FALSE],
    );
  }

}
