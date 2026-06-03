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
 * Finds .patch files or PATCHES.txt in modules/contrib/ directories.
 */
#[AuditCheck(
  id: 'fs_contrib_patched',
  label: new TranslatableMarkup('Contrib Patch Indicators'),
  description: new TranslatableMarkup('Finds .patch files or PATCHES.txt in modules/contrib/ directories.'),
  scope: 'site',
  category: 'Filesystem Health',
)]
class ContribPatchIndicatorsCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    string $drupalRoot,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
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
      return $this->pass(
        'No patch files or PATCHES.txt indicators were found in contrib module directories.',
        'None found',
        'None',
        $details,
      );
    }

    return $this->warning(
      sprintf('%d patch file(s) found across %d contrib module(s). Ensure patches are tracked in composer.json (e.g. via cweagans/composer-patches) and reviewed before each module update.', $patchFileCount, $patchedModuleCount),
      sprintf('%d patch file(s) across %d module(s)', $patchFileCount, $patchedModuleCount),
      'None',
      $details,
    );
  }

}
