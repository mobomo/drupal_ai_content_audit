<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scans contrib and custom module directories for .info.yml files not recognised by ModuleHandler.
 */
#[AuditCheck(
  id: 'fs_orphaned_modules',
  label: new TranslatableMarkup('Orphaned Modules'),
  description: new TranslatableMarkup('Scans contrib and custom module directories for .info.yml files not recognised by ModuleHandler.'),
  scope: 'site',
  category: 'Filesystem Health',
)]
class OrphanedModulesCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    string $drupalRoot,
    private readonly ModuleHandlerInterface $moduleHandler,
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
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
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
      return $this->info(
        'No orphaned modules were detected on disk.',
        '0',
        '0',
        $details,
      );
    }

    if ($orphanedCount <= 5) {
      return $this->info(
        sprintf('%d module(s) found on disk are not registered with the module handler. Review and remove unused module directories.', $orphanedCount),
        (string) $orphanedCount,
        '0',
        $details,
      );
    }

    return $this->warning(
      sprintf('%d unregistered module directories were found. A large number of orphaned modules increases attack surface and may cause confusion.', $orphanedCount),
      (string) $orphanedCount,
      '0',
      $details,
    );
  }

}
