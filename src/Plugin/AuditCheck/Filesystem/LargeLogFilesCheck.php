<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Scans sites/default/files/ and webroot for .log files exceeding 50 MB.
 */
#[AuditCheck(
  id: 'fs_large_logs',
  label: new TranslatableMarkup('Large Log Files'),
  description: new TranslatableMarkup('Scans sites/default/files/ and webroot for .log files exceeding 50 MB.'),
  scope: 'site',
  category: 'Filesystem Health',
)]
class LargeLogFilesCheck extends FilesystemCheckBase {

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $largeCount = 0;

    // Directories/files to check.
    $scanTargets = [];

    $filesDir = $this->safePath('sites/default/files');
    if ($filesDir !== NULL && is_dir($filesDir)) {
      $scanTargets[] = $filesDir;
    }

    // Root-level well-known log files.
    foreach (['error_log', 'php_errors.log'] as $logName) {
      $logPath = $this->safePath($logName);
      if ($logPath !== NULL && is_file($logPath)) {
        $size = filesize($logPath);
        if ($size !== FALSE && $size > self::LARGE_FILE_THRESHOLD_BYTES) {
          $largeCount++;
        }
      }
    }

    // Scan sites/default/files/ for *.log files.
    foreach ($scanTargets as $dir) {
      try {
        $dirIter = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterIter = new \RecursiveIteratorIterator($dirIter);
        $iterIter->setMaxDepth(self::MAX_SCAN_DEPTH);

        foreach ($iterIter as $file) {
          /** @var \SplFileInfo $file */
          if ($file->getExtension() !== 'log') {
            continue;
          }
          $size = $file->getSize();
          if ($size > self::LARGE_FILE_THRESHOLD_BYTES) {
            $largeCount++;
          }
        }
      }
      catch (\UnexpectedValueException) {
        // Skip unreadable directories silently.
      }
    }

    $details = ['large_file_count' => $largeCount];

    if ($largeCount === 0) {
      return $this->pass(
        'No log files exceeding 50 MB were detected.',
        '0',
        '0',
        $details,
      );
    }

    return $this->warning(
      sprintf('%d log file(s) exceed 50 MB. Large log files consume disk space and may indicate runaway logging; consider log rotation.', $largeCount),
      (string) $largeCount,
      '0',
      $details,
    );
  }

}
