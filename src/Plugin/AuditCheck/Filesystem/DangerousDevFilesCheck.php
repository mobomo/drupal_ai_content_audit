<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scans for 11 dangerous files such as phpinfo.php, adminer.php, .env in the webroot.
 */
#[AuditCheck(
  id: 'fs_dev_files',
  label: new TranslatableMarkup('Dangerous Dev Files'),
  description: new TranslatableMarkup('Scans for 11 dangerous files such as phpinfo.php, adminer.php, .env in the webroot.'),
  scope: 'site',
  category: 'Security',
)]
class DangerousDevFilesCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

  /**
   * List of dangerous development/diagnostic file names to scan for.
   */
  private const DANGEROUS_DEV_FILES = [
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
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    string $drupalRoot,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $drupalRoot);
  }

  /**
   *
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('app.root'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
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
      return $this->pass(
        'No known dangerous development or diagnostic files were found in the webroot.',
        'None found',
        'None present',
        ['files_found' => []],
      );
    }

    return $this->fail(
      'One or more dangerous development or diagnostic files were found in the webroot. Remove these files from production environments immediately.',
      implode(', ', $found),
      'None present',
      ['files_found' => $found],
    );
  }

}
