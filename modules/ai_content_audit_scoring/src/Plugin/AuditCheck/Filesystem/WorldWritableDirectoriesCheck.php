<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit_scoring\Attribute\AuditCheck;
use Drupal\ai_content_audit_scoring\ValueObject\TechnicalAuditResult;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Recursively scans webroot (max depth 3) for world-writable directories.
 */
#[AuditCheck(
  id: 'fs_world_writable',
  label: new TranslatableMarkup('World-Writable Directories'),
  description: new TranslatableMarkup('Recursively scans webroot (max depth 3) for world-writable directories.'),
  scope: 'site',
  category: 'Security',
)]
class WorldWritableDirectoriesCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    string $drupalRoot,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $drupalRoot);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('app.root'),
      $container->get('logger.factory')->get('ai_content_audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
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
      $this->logger->warning('WorldWritableDirectoriesCheck: Unable to iterate directories: @msg', ['@msg' => $e->getMessage()]);
    }

    if ($count === 0) {
      return $this->pass(
        'No world-writable directories were found in the webroot (excluding the managed files directory).',
        (string) $count,
        '0',
        ['world_writable_count' => $count],
      );
    }

    if ($count <= 3) {
      return $this->warning(
        sprintf('%d world-writable director%s found (excluding the managed files directory). Review and restrict permissions.', $count, $count === 1 ? 'y' : 'ies'),
        (string) $count,
        '0',
        ['world_writable_count' => $count],
      );
    }

    return $this->fail(
      sprintf('%d world-writable directories found (excluding the managed files directory). This is a significant security risk; restrict permissions immediately.', $count),
      (string) $count,
      '0',
      ['world_writable_count' => $count],
    );
  }

}
