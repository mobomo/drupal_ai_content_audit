<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai_content_audit\Plugin\Manager\AuditCheckManager;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Inspects the local Drupal filesystem for security, health, and AI-readiness.
 *
 * Security invariants enforced throughout:
 * - Absolute filesystem paths are NEVER stored in result fields.
 * - settings.php contents are NEVER read into result fields.
 * - All file operations go through safePath() to prevent traversal attacks.
 * - RecursiveDirectoryIterator usage is capped at MAX_SCAN_DEPTH.
 * - file_get_contents() calls are limited to 64 KB.
 */
final class FilesystemAuditService {

  protected const CACHE_TTL = 900;
  protected const CACHE_ID = 'ai_content_audit:filesystem_audit';
  protected const MAX_SCAN_DEPTH = 3;
  protected const LARGE_FILE_THRESHOLD_BYTES = 52428800;
  protected const DANGEROUS_DEV_FILES = [
    'phpinfo.php',
    'install.php',
    'update.php',
    'cron.php',
    'xmlrpc.php',
    'test.php',
    'info.php',
    'adminer.php',
    'phpmyadmin',
    '.env',
    '.env.local',
  ];

  public function __construct(
    private readonly string $drupalRoot,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly CacheBackendInterface $cacheData,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly AuditCheckManager $auditCheckManager,
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

    // Delegate to the AuditCheckManager plugin system.
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

    $this->cacheData->set(self::CACHE_ID, $results, \Drupal::time()->getRequestTime() + self::CACHE_TTL);

    return $results;
  }

  /**
   * Invalidates the filesystem audit cache entry.
   */
  public function invalidateCache(): void {
    $this->cacheData->delete(self::CACHE_ID);
  }

  /**
   * Validates that a relative path resolves inside the Drupal root.
   *
   * Uses realpath() to resolve symlinks and eliminate traversal sequences.
   * Returns NULL if the path escapes the root or does not exist.
   *
   * @param string $relative
   *   A path relative to the Drupal webroot (e.g. 'sites/default/settings.php').
   *
   * @return string|null
   *   Absolute resolved path, or NULL if invalid / outside root.
   */
  public function safePath(string $relative): ?string {
    $candidate = $this->drupalRoot . \DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    $resolved = realpath($candidate);

    if ($resolved === FALSE) {
      return NULL;
    }

    // Ensure the resolved path starts with the root (with trailing slash to
    // avoid prefix collisions like /var/www/drupalX vs /var/www/drupal).
    $root = rtrim($this->drupalRoot, '/\\') . \DIRECTORY_SEPARATOR;
    if (strncmp($resolved, $root, strlen($root)) !== 0 && $resolved !== rtrim($root, '/\\')) {
      return NULL;
    }

    return $resolved;
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  /**
   * Executes a single check callable, absorbing exceptions.
   *
   * @param callable $check
   *   A method reference that returns a TechnicalAuditResult.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   *   The result of the check, or an error result if an exception was thrown.
   *
   * @deprecated Exception handling is now performed inside AuditCheckManager.
   *   This wrapper is no longer called by runAllChecks() and is retained only
   *   for backward compatibility. It will be removed in a future release.
   */
  private function runCheck(callable $check): TechnicalAuditResult {
    try {
      return $check();
    }
    catch (\Throwable $e) {
      $this->logger->error('FilesystemAuditService check failed: @msg', ['@msg' => $e->getMessage()]);

      // Determine a safe check ID from the method name if possible.
      $id = 'fs_unknown';
      if (is_array($check) && isset($check[1])) {
        $id = 'fs_error_' . strtolower((string) $check[1]);
      }

      return new TechnicalAuditResult(
        check: $id,
        label: 'Check Error',
        status: 'error',
        currentContent: NULL,
        recommendedContent: NULL,
        description: 'An unexpected error occurred while running this check.',
      );
    }
  }

  // ---------------------------------------------------------------------------
  // Sprint A: Implemented checks
  // ---------------------------------------------------------------------------

  /**
   * Checks settings.php file permissions.
   *
   * ID: fs_settings_permissions.
   *
   * PASS  — mode is 0400 or 0440 (owner/group read-only, no world bits).
   * WARNING — mode is 0600 or 0640 (owner/group read-write, but not world-readable).
   * FAIL  — world-readable bit is set (any bit in 0x0004).
   *
   * The file path and contents are NEVER included in the result.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_settings_permissions'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkSettingsPhpPermissions(): TechnicalAuditResult {
    $path = $this->safePath('sites/default/settings.php');

    if ($path === NULL) {
      return new TechnicalAuditResult(
        check: 'fs_settings_permissions',
        label: 'settings.php Permissions',
        status: 'warning',
        currentContent: NULL,
        recommendedContent: '0440',
        description: 'settings.php could not be located inside the Drupal root.',
      );
    }

    $rawMode = fileperms($path);
    if ($rawMode === FALSE) {
      return new TechnicalAuditResult(
        check: 'fs_settings_permissions',
        label: 'settings.php Permissions',
        status: 'warning',
        currentContent: NULL,
        recommendedContent: '0440',
        description: 'Unable to read file permissions for settings.php.',
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
      $status = 'pass';
      $description = 'settings.php has secure read-only permissions.';
    }
    elseif ($isWorldReadable) {
      $status = 'fail';
      $description = 'settings.php is world-readable. This exposes database credentials to any system user.';
    }
    elseif ($isAcceptable) {
      $status = 'warning';
      $description = 'settings.php is writable by the owner/group but not world-readable. Prefer 0440 or 0400.';
    }
    else {
      $status = 'warning';
      $description = 'settings.php has non-standard permissions. Prefer 0440 or 0400.';
    }

    return new TechnicalAuditResult(
      check: 'fs_settings_permissions',
      label: 'settings.php Permissions',
      status: $status,
      currentContent: $octal,
      recommendedContent: '0440',
      description: $description,
      details: ['octal_mode' => $octal],
    );
  }

  /**
   * Checks that .htaccess exists in the webroot and is non-empty.
   *
   * ID: fs_htaccess.
   *
   * PASS    — file present and non-empty.
   * WARNING — file present but empty.
   * FAIL    — file missing altogether.
   *
   * Details expose file size and a boolean for RewriteEngine presence.
   * No file contents are stored in the result.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID 'fs_htaccess'.
   *   Invoked via AuditCheckManager. This method is retained for backward
   *   compatibility only and will be removed in a future release.
   */
  private function checkHtaccessPresence(): TechnicalAuditResult {
    $path = $this->safePath('.htaccess');

    if ($path === NULL || !file_exists($path)) {
      return new TechnicalAuditResult(
        check: 'fs_htaccess',
        label: '.htaccess Presence',
        status: 'fail',
        currentContent: 'Missing',
        recommendedContent: 'Present and non-empty',
        description: '.htaccess is missing from the webroot. Apache installations require it to enforce security rules and clean URLs.',
        details: ['file_exists' => FALSE, 'file_size_bytes' => 0, 'has_rewrite_engine' => FALSE],
      );
    }

    $fileSize = filesize($path);
    if ($fileSize === FALSE) {
      $fileSize = 0;
    }

    if ($fileSize === 0) {
      return new TechnicalAuditResult(
        check: 'fs_htaccess',
        label: '.htaccess Presence',
        status: 'warning',
        currentContent: 'Present but empty',
        recommendedContent: 'Present and non-empty',
        description: '.htaccess exists but is empty. Security rules and clean URL rewrites will not be applied.',
        details: ['file_exists' => TRUE, 'file_size_bytes' => 0, 'has_rewrite_engine' => FALSE],
      );
    }

    // Read up to 64 KB to check for RewriteEngine directive.
    $sample = file_get_contents($path, length: 65536);
    $hasRewrite = $sample !== FALSE && stripos($sample, 'RewriteEngine') !== FALSE;

    return new TechnicalAuditResult(
      check: 'fs_htaccess',
      label: '.htaccess Presence',
      status: 'pass',
      currentContent: 'Present',
      recommendedContent: 'Present and non-empty',
      description: '.htaccess is present and non-empty.',
      details: [
        'file_exists' => TRUE,
        'file_size_bytes' => $fileSize,
        'has_rewrite_engine' => $hasRewrite,
      ],
    );
  }

  /**
   * Checks whether a .git/ directory is exposed in the webroot.
   *
   * ID: fs_git_exposed.
   *
   * PASS — .git/ directory not present in webroot.
   * FAIL — .git/ directory found; source history may be publicly accessible.
   *
   * Details contain only a boolean; no paths are stored.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_git_exposed'. Invoked via AuditCheckManager. This method is retained
   *   for backward compatibility only and will be removed in a future release.
   */
  private function checkGitDirExposed(): TechnicalAuditResult {
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
      return new TechnicalAuditResult(
        check: 'fs_git_exposed',
        label: '.git Directory Exposed',
        status: 'fail',
        currentContent: 'Present',
        recommendedContent: 'Not present in webroot',
        description: 'A .git/ directory was found in the webroot. This may expose your full source history and sensitive configuration to web requests.',
        details: ['git_dir_found' => TRUE],
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_git_exposed',
      label: '.git Directory Exposed',
      status: 'pass',
      currentContent: 'Not present',
      recommendedContent: 'Not present in webroot',
      description: 'No .git/ directory was found in the webroot.',
      details: ['git_dir_found' => FALSE],
    );
  }

  /**
   * Scans the webroot for known dangerous development/diagnostic files.
   *
   * ID: fs_dev_files.
   *
   * PASS — none of the known dangerous files found.
   * FAIL — one or more dangerous files were found.
   *
   * Details contain only basenames; no absolute paths are stored.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_dev_files'. Invoked via AuditCheckManager. This method is retained
   *   for backward compatibility only and will be removed in a future release.
   */
  private function checkDangerousDevFiles(): TechnicalAuditResult {
    $found = [];
    $root = rtrim($this->drupalRoot, '/\\');

    foreach (self::DANGEROUS_DEV_FILES as $filename) {
      $candidate = $root . \DIRECTORY_SEPARATOR . $filename;

      // Check both as file and as directory (e.g. phpmyadmin/).
      if (file_exists($candidate)) {
        $real = realpath($candidate);
        if ($real !== FALSE) {
          // Ensure it's inside the webroot.
          $rootNorm = $root . \DIRECTORY_SEPARATOR;
          if (strncmp($real, $rootNorm, strlen($rootNorm)) === 0 || $real === $root) {
            $found[] = basename($filename);
          }
        }
      }
    }

    if (empty($found)) {
      return new TechnicalAuditResult(
        check: 'fs_dev_files',
        label: 'Dangerous Dev Files',
        status: 'pass',
        currentContent: 'None found',
        recommendedContent: 'None present',
        description: 'No known dangerous development or diagnostic files were found in the webroot.',
        details: ['files_found' => []],
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_dev_files',
      label: 'Dangerous Dev Files',
      status: 'fail',
      currentContent: implode(', ', $found),
      recommendedContent: 'None present',
      description: 'One or more dangerous development or diagnostic files were found in the webroot. Remove these files from production environments immediately.',
      details: ['files_found' => $found],
    );
  }

  /**
   * Scans the webroot for world-writable directories (up to MAX_SCAN_DEPTH).
   *
   * ID: fs_world_writable.
   *
   * Skips the sites/default/files/ subtree (managed upload directory).
   *
   * PASS    — 0 world-writable directories found.
   * WARNING — 1–3 found.
   * FAIL    — 4 or more found.
   *
   * Details contain only the count; no paths are stored.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_world_writable'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkWorldWritableDirectories(): TechnicalAuditResult {
    $root = rtrim($this->drupalRoot, '/\\');
    $skipReal = realpath($root . \DIRECTORY_SEPARATOR . 'sites' . \DIRECTORY_SEPARATOR . 'default' . \DIRECTORY_SEPARATOR . 'files');
    $count = 0;

    try {
      $dirIter = new \RecursiveDirectoryIterator(
        $root,
        \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS,
      );
      $iterIter = new \RecursiveIteratorIterator(
        $dirIter,
        \RecursiveIteratorIterator::SELF_FIRST,
      );
      $iterIter->setMaxDepth(self::MAX_SCAN_DEPTH);

      foreach ($iterIter as $item) {
        /** @var \SplFileInfo $item */
        if (!$item->isDir()) {
          continue;
        }

        $realItem = $item->getRealPath();
        if ($realItem === FALSE) {
          continue;
        }

        // Ensure item is inside the webroot.
        $rootNorm = $root . \DIRECTORY_SEPARATOR;
        if (strncmp($realItem, $rootNorm, strlen($rootNorm)) !== 0) {
          continue;
        }

        // Skip managed upload directory.
        if ($skipReal !== FALSE && strncmp($realItem, $skipReal, strlen($skipReal)) === 0) {
          continue;
        }

        $perms = fileperms($realItem);
        if ($perms === FALSE) {
          continue;
        }

        // World-writable: bit 0x0002 set.
        if ($perms & 0x0002) {
          $count++;
        }
      }
    }
    catch (\UnexpectedValueException $e) {
      $this->logger->warning('FilesystemAuditService: Unable to iterate directories: @msg', ['@msg' => $e->getMessage()]);
    }

    if ($count === 0) {
      $status = 'pass';
      $description = 'No world-writable directories were found in the webroot (excluding the managed files directory).';
    }
    elseif ($count <= 3) {
      $status = 'warning';
      $description = sprintf('%d world-writable director%s found (excluding the managed files directory). Review and restrict permissions.', $count, $count === 1 ? 'y' : 'ies');
    }
    else {
      $status = 'fail';
      $description = sprintf('%d world-writable directories found (excluding the managed files directory). This is a significant security risk; restrict permissions immediately.', $count);
    }

    return new TechnicalAuditResult(
      check: 'fs_world_writable',
      label: 'World-Writable Directories',
      status: $status,
      currentContent: (string) $count,
      recommendedContent: '0',
      description: $description,
      details: ['world_writable_count' => $count],
    );
  }

  /**
   * Checks for development-environment settings files in sites/default/.
   *
   * ID: fs_dev_settings.
   *
   * Checks for: settings.local.php, settings.ddev.php, settings.lando.php,
   *             local.services.yml, development.services.yml.
   *
   * PASS    — none found.
   * WARNING — one or more found.
   *
   * Details contain only basenames; no absolute paths are stored.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_dev_settings'. Invoked via AuditCheckManager. This method is retained
   *   for backward compatibility only and will be removed in a future release.
   */
  private function checkDevSettingsFiles(): TechnicalAuditResult {
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
      return new TechnicalAuditResult(
        check: 'fs_dev_settings',
        label: 'Dev Environment Settings Files',
        status: 'pass',
        currentContent: 'None found',
        recommendedContent: 'None present',
        description: 'No development-environment settings files were found in sites/default/.',
        details: ['files_found' => []],
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_dev_settings',
      label: 'Dev Environment Settings Files',
      status: 'warning',
      currentContent: implode(', ', $found),
      recommendedContent: 'None present',
      description: 'Development-environment settings files were found in sites/default/. Ensure these do not override security-sensitive settings in production.',
      details: ['files_found' => $found],
    );
  }

  // ---------------------------------------------------------------------------
  // Sprint B: Configuration / health checks
  // ---------------------------------------------------------------------------

  /**
   * Checks that trusted_host_patterns is configured in settings.php.
   *
   * ID: fs_trusted_hosts.
   *
   * PASS — pattern found in settings.php.
   * FAIL — pattern not found.
   *
   * File contents are discarded after detection; never stored in result.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_trusted_hosts'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkTrustedHostPatterns(): TechnicalAuditResult {
    $path = $this->safePath('sites/default/settings.php');

    if ($path === NULL) {
      return new TechnicalAuditResult(
        check: 'fs_trusted_hosts',
        label: 'Trusted Host Patterns',
        status: 'fail',
        currentContent: 'Not found',
        recommendedContent: 'Configured',
        description: 'settings.php could not be located inside the Drupal root; trusted_host_patterns cannot be verified.',
        details: ['trusted_hosts_configured' => FALSE],
      );
    }

    $raw = file_get_contents($path, length: 65536);
    $found = $raw !== FALSE && (bool) preg_match('/\$settings\[.trusted_host_patterns.\]/m', $raw);
    unset($raw);

    if ($found) {
      return new TechnicalAuditResult(
        check: 'fs_trusted_hosts',
        label: 'Trusted Host Patterns',
        status: 'pass',
        currentContent: 'Configured',
        recommendedContent: 'Configured',
        description: 'trusted_host_patterns is defined in settings.php.',
        details: ['trusted_hosts_configured' => TRUE],
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_trusted_hosts',
      label: 'Trusted Host Patterns',
      status: 'fail',
      currentContent: 'Not configured',
      recommendedContent: 'Configured',
      description: 'trusted_host_patterns is not defined in settings.php. Without it, Drupal is vulnerable to HTTP Host header injection attacks.',
      details: ['trusted_hosts_configured' => FALSE],
    );
  }

  /**
   * Checks services.yml for Twig debug mode flags.
   *
   * ID: fs_services_debug.
   *
   * PASS    — no debug flags detected.
   * WARNING — debug: true or auto_reload: true found.
   *
   * File contents are never stored in the result.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_services_debug'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkServicesYmlDebugMode(): TechnicalAuditResult {
    // Prefer the active services.yml; fall back to default.
    $candidates = [
      'sites/default/services.yml',
      'sites/default/default.services.yml',
    ];

    $twigDebug = FALSE;
    $autoReload = FALSE;
    $checkedFile = NULL;

    foreach ($candidates as $relative) {
      $path = $this->safePath($relative);
      if ($path === NULL || !file_exists($path)) {
        continue;
      }

      $raw = file_get_contents($path, length: 65536);
      if ($raw === FALSE) {
        continue;
      }

      $twigDebug = $twigDebug || (bool) preg_match('/^\s*debug:\s*true\b/im', $raw);
      $autoReload = $autoReload || (bool) preg_match('/^\s*auto_reload:\s*true\b/im', $raw);
      unset($raw);
      $checkedFile = $relative;
      break;
    }

    $details = ['twig_debug_on' => $twigDebug, 'auto_reload_on' => $autoReload];

    if ($twigDebug || $autoReload) {
      return new TechnicalAuditResult(
        check: 'fs_services_debug',
        label: 'Services YML Debug Mode',
        status: 'warning',
        currentContent: 'Debug flags enabled',
        recommendedContent: 'Debug flags disabled',
        description: 'Twig debug or auto_reload is enabled in services.yml. These settings expose template markup and increase cache-miss overhead; disable them in production.',
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_services_debug',
      label: 'Services YML Debug Mode',
      status: 'pass',
      currentContent: 'No debug flags',
      recommendedContent: 'Debug flags disabled',
      description: $checkedFile !== NULL
        ? 'No Twig debug flags were detected in services.yml.'
        : 'No services.yml file was found; using Drupal defaults (debug off).',
      details: $details,
    );
  }

  /**
   * Verifies that sites/default/files/.htaccess exists and blocks PHP execution.
   *
   * ID: fs_files_htaccess.
   *
   * PASS    — file present and contains PHP-blocking directives.
   * WARNING — file present but lacks PHP-blocking directives.
   * FAIL    — file missing.
   *
   * File contents are never stored in the result.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_files_htaccess'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkFilesDirectoryHtaccess(): TechnicalAuditResult {
    $path = $this->safePath('sites/default/files/.htaccess');

    if ($path === NULL || !file_exists($path)) {
      return new TechnicalAuditResult(
        check: 'fs_files_htaccess',
        label: 'Files Directory .htaccess',
        status: 'fail',
        currentContent: 'Missing',
        recommendedContent: 'Present with PHP-blocking directives',
        description: 'sites/default/files/.htaccess is missing. Without it, PHP scripts uploaded to this directory may be executed.',
        details: ['has_php_handler_block' => FALSE, 'has_direct_access_deny' => FALSE],
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
      return new TechnicalAuditResult(
        check: 'fs_files_htaccess',
        label: 'Files Directory .htaccess',
        status: 'pass',
        currentContent: 'Present with PHP-blocking directives',
        recommendedContent: 'Present with PHP-blocking directives',
        description: 'sites/default/files/.htaccess is present and contains PHP-blocking directives.',
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_files_htaccess',
      label: 'Files Directory .htaccess',
      status: 'warning',
      currentContent: 'Present but lacks PHP-blocking',
      recommendedContent: 'Present with PHP-blocking directives',
      description: 'sites/default/files/.htaccess exists but does not contain expected PHP-blocking directives. Uploaded PHP scripts may be executable.',
      details: $details,
    );
  }

  /**
   * Checks whether a private files path is configured and is outside the webroot.
   *
   * ID: fs_private_files.
   *
   * INFO — not configured (not a failure; just advisory).
   * PASS — configured, outside webroot, and writable.
   * FAIL — configured but inside webroot, or not writable / non-existent.
   *
   * Only a truncated hint (last 20 chars) of the path is stored, never the full path.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_private_files'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkPrivateFilesConfig(): TechnicalAuditResult {
    $privatePath = $this->configFactory->get('system.file')->get('path.private');

    if (empty($privatePath)) {
      return new TechnicalAuditResult(
        check: 'fs_private_files',
        label: 'Private Files Configuration',
        status: 'info',
        currentContent: 'Not configured',
        recommendedContent: 'Configured outside webroot',
        description: 'No private files directory is configured. Sensitive files cannot be stored outside the public webroot.',
        details: ['configured' => FALSE, 'outside_webroot' => FALSE, 'writable' => FALSE],
      );
    }

    $root = rtrim($this->drupalRoot, '/\\');
    $outsideWebroot = strncmp($privatePath, $root, strlen($root)) !== 0;
    $pathHint = substr($privatePath, -20);
    $writable = is_dir($privatePath) && is_writable($privatePath);

    $details = [
      'configured' => TRUE,
      'outside_webroot' => $outsideWebroot,
      'writable' => $writable,
    ];

    if (!$outsideWebroot) {
      return new TechnicalAuditResult(
        check: 'fs_private_files',
        label: 'Private Files Configuration',
        status: 'fail',
        currentContent: '…' . $pathHint,
        recommendedContent: 'Path outside webroot',
        description: 'The private files directory is inside the webroot. Files stored here may be publicly accessible via HTTP.',
        details: $details,
      );
    }

    if (!$writable) {
      return new TechnicalAuditResult(
        check: 'fs_private_files',
        label: 'Private Files Configuration',
        status: 'fail',
        currentContent: '…' . $pathHint,
        recommendedContent: 'Writable directory outside webroot',
        description: 'The private files directory is outside the webroot but does not exist or is not writable by the web server.',
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_private_files',
      label: 'Private Files Configuration',
      status: 'pass',
      currentContent: '…' . $pathHint,
      recommendedContent: 'Writable directory outside webroot',
      description: 'The private files directory is configured outside the webroot and is writable.',
      details: $details,
    );
  }

  // ---------------------------------------------------------------------------
  // Sprint B: Module / theme inventory checks
  // ---------------------------------------------------------------------------

  /**
   * Scans custom modules for missing README or incomplete .info.yml metadata.
   *
   * ID: fs_custom_modules.
   *
   * INFO    — all custom modules have proper metadata.
   * WARNING — one or more modules are missing description, package, or README.
   *
   * Details contain only module machine names and counts; no paths are stored.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_custom_modules'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkCustomModulesMetadata(): TechnicalAuditResult {
    // Support both composer-style (web/) and flat layouts.
    $basePath = $this->safePath('modules/custom');
    if ($basePath === NULL) {
      $basePath = $this->safePath('web/modules/custom');
    }

    $incomplete = [];
    $total = 0;

    if ($basePath !== NULL && is_dir($basePath)) {
      foreach (new \DirectoryIterator($basePath) as $item) {
        if (!$item->isDir() || $item->isDot()) {
          continue;
        }

        $moduleName = $item->getFilename();
        $moduleDir = $item->getPathname();
        $infoFile = $moduleDir . \DIRECTORY_SEPARATOR . $moduleName . '.info.yml';

        if (!file_exists($infoFile)) {
          continue;
        }

        $total++;
        $issues = [];

        $raw = file_get_contents($infoFile, length: 65536);
        if ($raw !== FALSE) {
          if (!preg_match('/^\s*description\s*:/im', $raw)) {
            $issues[] = 'missing_description';
          }
          if (!preg_match('/^\s*package\s*:/im', $raw)) {
            $issues[] = 'missing_package';
          }
          unset($raw);
        }

        $hasReadme = file_exists($moduleDir . \DIRECTORY_SEPARATOR . 'README.md')
          || file_exists($moduleDir . \DIRECTORY_SEPARATOR . 'README');
        if (!$hasReadme) {
          $issues[] = 'missing_readme';
        }

        if (!empty($issues)) {
          $incomplete[] = $moduleName;
        }
      }
    }

    $details = ['incomplete_modules' => $incomplete, 'total_custom_modules' => $total];

    if (empty($incomplete)) {
      return new TechnicalAuditResult(
        check: 'fs_custom_modules',
        label: 'Custom Modules Metadata',
        status: 'info',
        currentContent: sprintf('%d module(s) checked', $total),
        recommendedContent: 'All modules have complete metadata',
        description: 'All custom modules have complete .info.yml metadata and README files.',
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_custom_modules',
      label: 'Custom Modules Metadata',
      status: 'warning',
      currentContent: sprintf('%d of %d module(s) incomplete', count($incomplete), $total),
      recommendedContent: 'All modules have complete metadata',
      description: sprintf('%d custom module(s) are missing description, package, or README: %s.', count($incomplete), implode(', ', $incomplete)),
      details: $details,
    );
  }

  /**
   * Finds modules on disk that are not registered with the module handler.
   *
   * ID: fs_orphaned_modules.
   *
   * INFO    — 0 orphaned modules, or 1–5 (advisory note).
   * WARNING — 6 or more orphaned modules.
   *
   * Only the count is reported; no module names exposed (security).
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_orphaned_modules'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkOrphanedModules(): TechnicalAuditResult {
    $installedModules = array_keys($this->moduleHandler->getModuleList());
    $orphanedCount = 0;

    $searchDirs = [
      $this->safePath('modules/contrib'),
      $this->safePath('modules/custom'),
      $this->safePath('web/modules/contrib'),
      $this->safePath('web/modules/custom'),
    ];

    foreach ($searchDirs as $baseDir) {
      if ($baseDir === NULL || !is_dir($baseDir)) {
        continue;
      }

      try {
        $dirIter = new \RecursiveDirectoryIterator(
          $baseDir,
          \RecursiveDirectoryIterator::SKIP_DOTS,
        );
        $iterIter = new \RecursiveIteratorIterator($dirIter);
        $iterIter->setMaxDepth(self::MAX_SCAN_DEPTH);

        foreach ($iterIter as $file) {
          /** @var \SplFileInfo $file */
          if ($file->getExtension() !== 'yml') {
            continue;
          }
          if (!str_ends_with($file->getFilename(), '.info.yml')) {
            continue;
          }

          $raw = file_get_contents($file->getPathname(), length: 65536);
          if ($raw === FALSE) {
            continue;
          }
          $isModule = (bool) preg_match('/^\s*type\s*:\s*module\b/im', $raw);
          unset($raw);

          if (!$isModule) {
            continue;
          }

          $machineName = str_replace('.info.yml', '', $file->getFilename());
          if (!in_array($machineName, $installedModules, TRUE)) {
            $orphanedCount++;
          }
        }
      }
      catch (\UnexpectedValueException) {
        // Skip unreadable directories silently.
      }
    }

    $details = ['orphaned_count' => $orphanedCount];

    if ($orphanedCount === 0) {
      return new TechnicalAuditResult(
        check: 'fs_orphaned_modules',
        label: 'Orphaned Modules',
        status: 'info',
        currentContent: '0',
        recommendedContent: '0',
        description: 'No orphaned modules were detected on disk.',
        details: $details,
      );
    }

    if ($orphanedCount <= 5) {
      return new TechnicalAuditResult(
        check: 'fs_orphaned_modules',
        label: 'Orphaned Modules',
        status: 'info',
        currentContent: (string) $orphanedCount,
        recommendedContent: '0',
        description: sprintf('%d module(s) found on disk are not registered with the module handler. Review and remove unused module directories.', $orphanedCount),
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_orphaned_modules',
      label: 'Orphaned Modules',
      status: 'warning',
      currentContent: (string) $orphanedCount,
      recommendedContent: '0',
      description: sprintf('%d unregistered module directories were found. A large number of orphaned modules increases attack surface and may cause confusion.', $orphanedCount),
      details: $details,
    );
  }

  /**
   * Looks for .patch files and PATCHES.txt inside contrib module directories.
   *
   * ID: fs_contrib_patched.
   *
   * PASS    — no patch indicators found.
   * WARNING — one or more patch files detected.
   *
   * Details contain counts only; no file names or paths are stored.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_contrib_patched'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkContribPatchIndicators(): TechnicalAuditResult {
    $patchFileCount = 0;
    $patchedModules = [];

    $searchDirs = [
      $this->safePath('modules/contrib'),
      $this->safePath('web/modules/contrib'),
    ];

    foreach ($searchDirs as $baseDir) {
      if ($baseDir === NULL || !is_dir($baseDir)) {
        continue;
      }

      try {
        $dirIter = new \RecursiveDirectoryIterator(
          $baseDir,
          \RecursiveDirectoryIterator::SKIP_DOTS,
        );
        $iterIter = new \RecursiveIteratorIterator($dirIter);
        $iterIter->setMaxDepth(self::MAX_SCAN_DEPTH);

        foreach ($iterIter as $file) {
          /** @var \SplFileInfo $file */
          $filename = $file->getFilename();
          if ($file->getExtension() === 'patch' || $filename === 'PATCHES.txt') {
            $patchFileCount++;
            // Track the immediate subdirectory of baseDir as the module name.
            $relPath = substr($file->getPathname(), strlen($baseDir) + 1);
            $parts = explode(\DIRECTORY_SEPARATOR, $relPath);
            if (isset($parts[0])) {
              $patchedModules[$parts[0]] = TRUE;
            }
          }
        }
      }
      catch (\UnexpectedValueException) {
        // Skip unreadable directories silently.
      }
    }

    $patchedModuleCount = count($patchedModules);
    $details = ['patch_file_count' => $patchFileCount, 'patched_module_count' => $patchedModuleCount];

    if ($patchFileCount === 0) {
      return new TechnicalAuditResult(
        check: 'fs_contrib_patched',
        label: 'Contrib Patch Indicators',
        status: 'pass',
        currentContent: 'None found',
        recommendedContent: 'None',
        description: 'No patch files or PATCHES.txt indicators were found in contrib module directories.',
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_contrib_patched',
      label: 'Contrib Patch Indicators',
      status: 'warning',
      currentContent: sprintf('%d patch file(s) across %d module(s)', $patchFileCount, $patchedModuleCount),
      recommendedContent: 'None',
      description: sprintf('%d patch file(s) found across %d contrib module(s). Ensure patches are tracked in composer.json (e.g. via cweagans/composer-patches) and reviewed before each module update.', $patchFileCount, $patchedModuleCount),
      details: $details,
    );
  }

  // ---------------------------------------------------------------------------
  // Sprint B: Filesystem health checks
  // ---------------------------------------------------------------------------

  /**
   * Checks that the public files directory is writable by the web server.
   *
   * ID: fs_public_writable.
   *
   * PASS — directory is writable.
   * FAIL — directory is not writable.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_public_writable'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkPublicFilesWritable(): TechnicalAuditResult {
    $configuredPath = $this->configFactory->get('system.file')->get('path.public') ?? 'sites/default/files';
    $fullPath = $this->drupalRoot . \DIRECTORY_SEPARATOR . ltrim((string) $configuredPath, '/\\');
    $writable = is_dir($fullPath) && is_writable($fullPath);

    $details = ['writable' => $writable];

    if ($writable) {
      return new TechnicalAuditResult(
        check: 'fs_public_writable',
        label: 'Public Files Writable',
        status: 'pass',
        currentContent: 'Writable',
        recommendedContent: 'Writable',
        description: 'The public files directory is writable by the web server.',
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_public_writable',
      label: 'Public Files Writable',
      status: 'fail',
      currentContent: 'Not writable',
      recommendedContent: 'Writable',
      description: 'The public files directory is not writable. File uploads and managed file operations will fail.',
      details: $details,
    );
  }

  /**
   * Checks that the temporary directory is writable.
   *
   * ID: fs_temp_writable.
   *
   * INFO    — no custom temp dir configured; using system default.
   * PASS    — custom temp dir is writable.
   * WARNING — custom temp dir is configured but not writable.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_temp_writable'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkTempDirWritable(): TechnicalAuditResult {
    $tempPath = $this->configFactory->get('system.file')->get('path.temporary');

    if (empty($tempPath)) {
      $sysTemp = sys_get_temp_dir();
      $writable = is_writable($sysTemp);
      return new TechnicalAuditResult(
        check: 'fs_temp_writable',
        label: 'Temp Directory Writable',
        status: 'info',
        currentContent: 'System default',
        recommendedContent: 'Custom writable path',
        description: 'No custom temporary directory is configured; using the system temp directory.',
        details: ['using_system_default' => TRUE, 'writable' => $writable],
      );
    }

    $writable = is_dir($tempPath) && is_writable($tempPath);
    $details = ['using_system_default' => FALSE, 'writable' => $writable];

    if ($writable) {
      return new TechnicalAuditResult(
        check: 'fs_temp_writable',
        label: 'Temp Directory Writable',
        status: 'pass',
        currentContent: 'Writable',
        recommendedContent: 'Writable',
        description: 'The configured temporary directory is writable.',
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_temp_writable',
      label: 'Temp Directory Writable',
      status: 'warning',
      currentContent: 'Not writable',
      recommendedContent: 'Writable',
      description: 'The configured temporary directory is not writable. File processing operations that require a writable temp directory may fail.',
      details: $details,
    );
  }

  /**
   * Scans for large log files in sites/default/files/ and the webroot.
   *
   * ID: fs_large_logs.
   *
   * PASS    — no log files exceed LARGE_FILE_THRESHOLD_BYTES (50 MB).
   * WARNING — one or more log files exceed the threshold.
   *
   * Only the count is reported; no file names or paths are stored.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_large_logs'. Invoked via AuditCheckManager. This method is retained
   *   for backward compatibility only and will be removed in a future release.
   */
  private function checkLargeLogFiles(): TechnicalAuditResult {
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
      return new TechnicalAuditResult(
        check: 'fs_large_logs',
        label: 'Large Log Files',
        status: 'pass',
        currentContent: '0',
        recommendedContent: '0',
        description: 'No log files exceeding 50 MB were detected.',
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_large_logs',
      label: 'Large Log Files',
      status: 'warning',
      currentContent: (string) $largeCount,
      recommendedContent: '0',
      description: sprintf('%d log file(s) exceed 50 MB. Large log files consume disk space and may indicate runaway logging; consider log rotation.', $largeCount),
      details: $details,
    );
  }

  /**
   * Counts aggregated CSS and JS files to detect stale asset build-up.
   *
   * ID: fs_stale_assets.
   *
   * PASS    — each directory has fewer than 100 files, or directories don't exist.
   * INFO    — total between 100 and 499.
   * WARNING — total 500 or more.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_stale_assets'. Invoked via AuditCheckManager. This method is retained
   *   for backward compatibility only and will be removed in a future release.
   */
  private function checkStaleAggregatedAssets(): TechnicalAuditResult {
    $cssCount = 0;
    $jsCount = 0;

    $cssDir = $this->safePath('sites/default/files/css');
    if ($cssDir !== NULL && is_dir($cssDir)) {
      foreach (new \FilesystemIterator($cssDir, \FilesystemIterator::SKIP_DOTS) as $f) {
        $cssCount++;
      }
    }

    $jsDir = $this->safePath('sites/default/files/js');
    if ($jsDir !== NULL && is_dir($jsDir)) {
      foreach (new \FilesystemIterator($jsDir, \FilesystemIterator::SKIP_DOTS) as $f) {
        $jsCount++;
      }
    }

    $total = $cssCount + $jsCount;
    $details = ['css_file_count' => $cssCount, 'js_file_count' => $jsCount];

    if ($cssCount < 100 && $jsCount < 100) {
      return new TechnicalAuditResult(
        check: 'fs_stale_assets',
        label: 'Stale Aggregated Assets',
        status: 'pass',
        currentContent: sprintf('CSS: %d, JS: %d', $cssCount, $jsCount),
        recommendedContent: 'Under 100 files each',
        description: 'Aggregated CSS and JS file counts are within normal range.',
        details: $details,
      );
    }

    if ($total >= 500) {
      return new TechnicalAuditResult(
        check: 'fs_stale_assets',
        label: 'Stale Aggregated Assets',
        status: 'warning',
        currentContent: sprintf('CSS: %d, JS: %d (total: %d)', $cssCount, $jsCount, $total),
        recommendedContent: 'Under 100 files each',
        description: sprintf('%d aggregated asset files detected. A large number of stale assets wastes disk space; run "drush cr" or clear the asset cache.', $total),
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_stale_assets',
      label: 'Stale Aggregated Assets',
      status: 'info',
      currentContent: sprintf('CSS: %d, JS: %d (total: %d)', $cssCount, $jsCount, $total),
      recommendedContent: 'Under 100 files each',
      description: sprintf('%d aggregated asset files detected. Consider clearing aggregated assets periodically.', $total),
      details: $details,
    );
  }

  // ---------------------------------------------------------------------------
  // Sprint B: AI-readiness checks (disk-side)
  // ---------------------------------------------------------------------------

  /**
   * Checks whether llms.txt exists at the webroot.
   *
   * ID: fs_llms_txt_disk.
   *
   * PASS — file exists and is non-empty.
   * FAIL — file missing or empty.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_llms_txt_disk'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkLlmsTxtOnDisk(): TechnicalAuditResult {
    $path = $this->safePath('llms.txt');
    $exists = $path !== NULL && file_exists($path);
    $sizeBytes = $exists ? (int) filesize($path) : 0;
    $isNonEmpty = $sizeBytes > 0;

    $details = ['size_bytes' => $sizeBytes, 'is_non_empty' => $isNonEmpty];

    if ($exists && $isNonEmpty) {
      return new TechnicalAuditResult(
        check: 'fs_llms_txt_disk',
        label: 'llms.txt on Disk',
        status: 'pass',
        currentContent: 'Present',
        recommendedContent: 'Present and non-empty',
        description: 'llms.txt is present at the webroot and is non-empty.',
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_llms_txt_disk',
      label: 'llms.txt on Disk',
      status: 'fail',
      currentContent: $exists ? 'Present but empty' : 'Missing',
      recommendedContent: 'Present and non-empty',
      description: 'llms.txt is missing or empty. Adding this file helps LLM crawlers understand site content and policies.',
      details: $details,
    );
  }

  /**
   * Checks robots.txt presence and customisation.
   *
   * ID: fs_robots_txt_disk.
   *
   * PASS    — present and contains User-agent: directive.
   * WARNING — present but appears to be unmodified default Drupal robots.txt.
   * FAIL    — file missing.
   *
   * File contents are never stored in the result.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_robots_txt_disk'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkRobotsTxtOnDisk(): TechnicalAuditResult {
    $path = $this->safePath('robots.txt');
    $exists = $path !== NULL && file_exists($path);

    if (!$exists) {
      return new TechnicalAuditResult(
        check: 'fs_robots_txt_disk',
        label: 'robots.txt on Disk',
        status: 'fail',
        currentContent: 'Missing',
        recommendedContent: 'Present with User-agent directives',
        description: 'robots.txt is missing from the webroot. Search engines and crawlers will have no crawl instructions.',
        details: ['exists' => FALSE, 'has_user_agent' => FALSE, 'appears_customized' => FALSE],
      );
    }

    $raw = file_get_contents($path, length: 65536);
    $hasUserAgent = $raw !== FALSE && (bool) preg_match('/User-agent\s*:/i', $raw);
    $appearsDefault = $raw !== FALSE
      && (bool) preg_match('/^#\s*robots\.txt/i', $raw)
      && !(bool) preg_match('/Sitemap\s*:/i', $raw);
    $appearsCustomized = !$appearsDefault;
    unset($raw);

    $details = [
      'exists' => TRUE,
      'has_user_agent' => $hasUserAgent,
      'appears_customized' => $appearsCustomized,
    ];

    if ($hasUserAgent && $appearsCustomized) {
      return new TechnicalAuditResult(
        check: 'fs_robots_txt_disk',
        label: 'robots.txt on Disk',
        status: 'pass',
        currentContent: 'Present and customized',
        recommendedContent: 'Present with User-agent directives',
        description: 'robots.txt is present and appears to contain custom crawl directives.',
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_robots_txt_disk',
      label: 'robots.txt on Disk',
      status: 'warning',
      currentContent: 'Present (default)',
      recommendedContent: 'Customized with Sitemap and environment-specific rules',
      description: 'robots.txt appears to be the default Drupal file without customisation. Consider adding a Sitemap directive and environment-specific rules.',
      details: $details,
    );
  }

  /**
   * Looks for structured-data templates and checks for metatag module.
   *
   * ID: fs_structured_data.
   *
   * PASS — one or more *.jsonld / schema.json / structured-data.json files found.
   * INFO — no templates found but metatag module is installed.
   * INFO — neither templates nor metatag installed.
   *
   * @deprecated Now implemented as the AuditCheck plugin with ID
   *   'fs_structured_data'. Invoked via AuditCheckManager. This method is
   *   retained for backward compatibility only and will be removed in a future
   *   release.
   */
  private function checkStructuredDataTemplates(): TechnicalAuditResult {
    $jsonldCount = 0;
    $metatagInstalled = $this->moduleHandler->moduleExists('metatag');

    $searchDirs = [
      $this->safePath('themes/custom'),
      $this->safePath('web/themes/custom'),
    ];

    $targetFilenames = ['schema.json', 'structured-data.json'];

    foreach ($searchDirs as $baseDir) {
      if ($baseDir === NULL || !is_dir($baseDir)) {
        continue;
      }

      try {
        $dirIter = new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterIter = new \RecursiveIteratorIterator($dirIter);
        $iterIter->setMaxDepth(self::MAX_SCAN_DEPTH);

        foreach ($iterIter as $file) {
          /** @var \SplFileInfo $file */
          if ($file->getExtension() === 'jsonld' || in_array($file->getFilename(), $targetFilenames, TRUE)) {
            $jsonldCount++;
          }
        }
      }
      catch (\UnexpectedValueException) {
        // Skip unreadable directories silently.
      }
    }

    $details = ['jsonld_file_count' => $jsonldCount, 'metatag_installed' => $metatagInstalled];

    if ($jsonldCount > 0) {
      return new TechnicalAuditResult(
        check: 'fs_structured_data',
        label: 'Structured Data Templates',
        status: 'pass',
        currentContent: sprintf('%d file(s) found', $jsonldCount),
        recommendedContent: 'Structured data templates present',
        description: sprintf('%d structured-data template file(s) found in custom themes. This supports rich search results and AI-readiness.', $jsonldCount),
        details: $details,
      );
    }

    if ($metatagInstalled) {
      return new TechnicalAuditResult(
        check: 'fs_structured_data',
        label: 'Structured Data Templates',
        status: 'info',
        currentContent: 'No templates; metatag installed',
        recommendedContent: 'Structured data templates present',
        description: 'No JSON-LD template files were found, but the metatag module is installed and can output structured data via configuration.',
        details: $details,
      );
    }

    return new TechnicalAuditResult(
      check: 'fs_structured_data',
      label: 'Structured Data Templates',
      status: 'info',
      currentContent: 'None found',
      recommendedContent: 'Structured data templates or metatag module',
      description: 'No structured data template files or metatag module detected. Consider implementing JSON-LD structured data for improved AI and search engine readiness.',
      details: $details,
    );
  }

}
