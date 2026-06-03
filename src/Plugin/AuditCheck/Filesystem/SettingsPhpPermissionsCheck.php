<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Checks settings.php file permissions are appropriately restrictive.
 */
#[AuditCheck(
  id: 'fs_settings_permissions',
  label: new TranslatableMarkup('Settings PHP Permissions'),
  description: new TranslatableMarkup('Checks settings.php file permissions are appropriately restrictive.'),
  scope: 'site',
  category: 'Security',
)]
class SettingsPhpPermissionsCheck extends FilesystemCheckBase {

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $path = $this->safePath('sites/default/settings.php');

    if ($path === NULL) {
      return $this->warning(
        'settings.php could not be located inside the Drupal root.',
        NULL,
        '0440',
      );
    }

    $rawMode = fileperms($path);
    if ($rawMode === FALSE) {
      return $this->warning(
        'Unable to read file permissions for settings.php.',
        NULL,
        '0440',
      );
    }

    // Extract the permission bits (lower 12 bits).
    $mode = $rawMode & 0x0FFF;
    $octal = sprintf('%04o', $mode);

    // World-readable if any of the 3 world-read bits are set.
    $isWorldReadable = (bool) ($mode & 0x0004);
    // Modes considered safe (owner/group read-only).
    $isSafe = in_array($mode, [0440, 0400], TRUE);
    // Modes considered acceptable (owner/group rw, no world write/read).
    $isAcceptable = in_array($mode, [0640, 0600], TRUE);

    if ($isSafe) {
      return $this->pass(
        'settings.php has secure read-only permissions.',
        $octal,
        '0440',
        ['octal_mode' => $octal],
      );
    }

    if ($isWorldReadable) {
      return $this->fail(
        'settings.php is world-readable. This exposes database credentials to any system user.',
        $octal,
        '0440',
        ['octal_mode' => $octal],
      );
    }

    return $this->warning(
      $isAcceptable
        ? 'settings.php is writable by the owner/group but not world-readable. Prefer 0440 or 0400.'
        : 'settings.php has non-standard permissions. Prefer 0440 or 0400.',
      $octal,
      '0440',
      ['octal_mode' => $octal],
    );
  }

}
