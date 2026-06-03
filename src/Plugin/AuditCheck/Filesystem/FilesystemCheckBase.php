<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Abstract base class for all filesystem-based AuditCheck plugins.
 *
 * Provides:
 * - $drupalRoot property (absolute path to the Drupal webroot).
 * - MAX_SCAN_DEPTH constant for recursive iterator depth caps.
 * - LARGE_FILE_THRESHOLD_BYTES constant (50 MB).
 * - safePath() path-traversal guard for filesystem checks.
 */
abstract class FilesystemCheckBase extends AuditCheckBase implements ContainerFactoryPluginInterface {

  /**
   * Maximum recursion depth for directory scans.
   */
  protected const MAX_SCAN_DEPTH = 3;

  /**
   * Threshold in bytes above which a log file is considered "large" (50 MB).
   */
  protected const LARGE_FILE_THRESHOLD_BYTES = 52428800;

  /**
   * Constructs a FilesystemCheckBase plugin.
   *
   * @param array $configuration
   *   Plugin configuration array.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition from the attribute.
   * @param string $drupalRoot
   *   Absolute path to the Drupal webroot (from the 'app.root' container parameter).
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly string $drupalRoot,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      (string) $container->getParameter('app.root'),
    );
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
  protected function safePath(string $relative): ?string {
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

}
