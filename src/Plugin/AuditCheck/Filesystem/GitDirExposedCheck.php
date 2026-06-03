<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Checks if the .git directory is accessible from the webroot.
 */
#[AuditCheck(
  id: 'fs_git_exposed',
  label: new TranslatableMarkup('Git Directory Exposure'),
  description: new TranslatableMarkup('Checks if the .git directory is accessible from the webroot.'),
  scope: 'site',
  category: 'Security',
)]
class GitDirExposedCheck extends FilesystemCheckBase {

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $root = rtrim($this->drupalRoot, '/\\');
    $gitDir = $root . \DIRECTORY_SEPARATOR . '.git';

    // We cannot use safePath() here because .git won't resolve via realpath
    // when it does not exist — that's one of the conditions we check.
    // We validate we are operating strictly within the known root.
    $gitDirReal = realpath($gitDir);
    $gitExists = $gitDirReal !== FALSE && is_dir($gitDirReal);

    // Verify the resolved path is still inside the webroot when it does exist.
    if ($gitExists) {
      $rootNorm = $root . \DIRECTORY_SEPARATOR;
      if (strncmp($gitDirReal, $rootNorm, strlen($rootNorm)) !== 0) {
        $gitExists = FALSE;
      }
    }

    if ($gitExists) {
      return $this->fail(
        'A .git/ directory was found in the webroot. This may expose your full source history and sensitive configuration to web requests.',
        'Present',
        'Not present in webroot',
        ['git_dir_found' => TRUE],
      );
    }

    return $this->pass(
      'No .git/ directory was found in the webroot.',
      'Not present',
      'Not present in webroot',
      ['git_dir_found' => FALSE],
    );
  }

}
