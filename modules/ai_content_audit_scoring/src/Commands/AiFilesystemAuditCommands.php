<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Commands;

use Symfony\Component\Yaml\Yaml;
use Drupal\ai_content_audit_scoring\Service\FilesystemAuditService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\Table;

/**
 * Drush commands for the filesystem audit feature.
 */
class AiFilesystemAuditCommands extends DrushCommands {

  /**
   * Category prefix map for grouping checks.
   */
  protected const CATEGORY_MAP = [
    'fs_settings_' => 'security',
    'fs_htaccess' => 'security',
    'fs_git_' => 'security',
    'fs_dev_' => 'security',
    'fs_world_' => 'security',
    'fs_trusted_' => 'config',
    'fs_services_' => 'config',
    'fs_files_' => 'config',
    'fs_private_' => 'config',
    'fs_custom_' => 'modules',
    'fs_orphaned_' => 'modules',
    'fs_contrib_' => 'modules',
    'fs_public_' => 'health',
    'fs_temp_' => 'health',
    'fs_large_' => 'health',
    'fs_stale_' => 'health',
    'fs_llms_' => 'ai',
    'fs_robots_' => 'ai',
    'fs_structured_' => 'ai',
  ];

  public function __construct(
    protected FilesystemAuditService $filesystemAuditService,
  ) {
    parent::__construct();
  }

  /**
   * Run the filesystem audit and display results.
   */
  #[CLI\Command(name: 'aica:filesystem-audit', aliases: ['aica-fs'])]
  #[CLI\Help(description: 'Runs filesystem security and health audit checks against the Drupal webroot.')]
  #[CLI\Option(name: 'refresh', description: 'Force cache invalidation before running.')]
  #[CLI\Option(name: 'format', description: 'Output format: table, json, or yaml.')]
  #[CLI\Option(name: 'category', description: 'Filter to a specific category: security, config, modules, health, ai.')]
  #[CLI\Option(name: 'fail-only', description: 'Show only checks with fail status.')]
  #[CLI\Usage(name: 'drush aica:filesystem-audit', description: 'Run all filesystem audit checks and display as a table.')]
  #[CLI\Usage(name: 'drush aica:filesystem-audit --refresh', description: 'Force re-run (bypass cache).')]
  #[CLI\Usage(name: 'drush aica:filesystem-audit --format=json', description: 'Output results as JSON.')]
  #[CLI\Usage(name: 'drush aica:filesystem-audit --category=security', description: 'Show only security checks.')]
  #[CLI\Usage(name: 'drush aica:filesystem-audit --fail-only', description: 'Show only failing checks (for CI).')]
  public function filesystemAudit(
    array $options = [
      'refresh' => FALSE,
      'format' => 'table',
      'category' => NULL,
      'fail-only' => FALSE,
    ],
  ): int {
    $forceRefresh = (bool) $options['refresh'];
    $format = $options['format'] ?? 'table';
    $categoryFilter = $options['category'] ?? NULL;
    $failOnly = (bool) $options['fail-only'];

    if ($forceRefresh) {
      $this->filesystemAuditService->invalidateCache();
      $this->logger()->info('Cache invalidated.');
    }

    $results = $this->filesystemAuditService->runAllChecks($forceRefresh);

    // Convert to arrays.
    $rows = [];
    foreach ($results as $result) {
      $row = is_object($result) && method_exists($result, 'toArray')
        ? $result->toArray()
        : (array) $result;
      $rows[] = $row;
    }

    // Filter by category.
    if ($categoryFilter !== NULL) {
      $categoryFilter = strtolower($categoryFilter);
      $rows = array_filter($rows, function (array $row) use ($categoryFilter) {
        return $this->getCheckCategory($row['check'] ?? '') === $categoryFilter;
      });
      $rows = array_values($rows);
    }

    // Filter fail-only.
    if ($failOnly) {
      $rows = array_filter($rows, fn(array $row) => ($row['status'] ?? '') === 'fail');
      $rows = array_values($rows);
    }

    if (empty($rows)) {
      $this->io()->success('No matching checks found.');
      return self::EXIT_SUCCESS;
    }

    // Determine exit code.
    $hasFail = FALSE;
    $hasWarning = FALSE;
    foreach ($rows as $row) {
      $status = $row['status'] ?? '';
      if ($status === 'fail') {
        $hasFail = TRUE;
      }
      if ($status === 'warning') {
        $hasWarning = TRUE;
      }
    }

    // Output.
    switch ($format) {
      case 'json':
        $this->output()->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        break;

      case 'yaml':
        $this->output()->writeln(Yaml::dump($rows, 4, 2));
        break;

      default:
        $this->renderTable($rows);
        break;
    }

    // Summary line.
    $passCount = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'pass'));
    $failCount = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'fail'));
    $warnCount = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'warning'));
    $infoCount = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'info'));

    if ($format === 'table') {
      $this->io()->newLine();
      $this->io()->text(sprintf(
        'Summary: <info>%d pass</info>, <error>%d fail</error>, <comment>%d warning</comment>, %d info (%d total)',
        $passCount, $failCount, $warnCount, $infoCount, count($rows)
      ));
    }

    // Exit codes for CI: 1 = fail, 2 = warning only, 0 = all pass.
    if ($hasFail) {
      return 1;
    }
    if ($hasWarning) {
      return 2;
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Renders a table of check results to the console.
   */
  protected function renderTable(array $rows): void {
    $table = new Table($this->output());
    $table->setHeaders(['Check', 'Status', 'Description']);

    foreach ($rows as $row) {
      $status = $row['status'] ?? 'unknown';
      $statusFormatted = match ($status) {
        'pass' => '<info>PASS</info>',
        'fail' => '<error>FAIL</error>',
        'warning' => '<comment>WARNING</comment>',
        'info' => 'INFO',
        default => strtoupper($status),
      };

      // Truncate description to 60 chars for readability.
      $desc = $row['description'] ?? '';
      if (strlen($desc) > 80) {
        $desc = substr($desc, 0, 77) . '...';
      }

      $table->addRow([
        $row['label'] ?? $row['check'] ?? 'unknown',
        $statusFormatted,
        $desc,
      ]);
    }

    $table->render();
  }

  /**
   * Determines the category of a check based on its ID prefix.
   */
  protected function getCheckCategory(string $checkId): string {
    foreach (self::CATEGORY_MAP as $prefix => $category) {
      if (str_starts_with($checkId, $prefix)) {
        return $category;
      }
    }
    return 'other';
  }

}
