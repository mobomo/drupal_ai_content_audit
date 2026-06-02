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
 * Checks each custom module .info.yml has description, package, and a README file.
 */
#[AuditCheck(
  id: 'fs_custom_modules',
  label: new TranslatableMarkup('Custom Modules Metadata'),
  description: new TranslatableMarkup('Checks each custom module .info.yml has description, package, and a README file.'),
  scope: 'site',
  category: 'Filesystem Health',
)]
class CustomModulesMetadataCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

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
      return $this->info(
        'All custom modules have complete .info.yml metadata and README files.',
        sprintf('%d module(s) checked', $total),
        'All modules have complete metadata',
        $details,
      );
    }

    return $this->warning(
      sprintf('%d custom module(s) are missing description, package, or README: %s.', count($incomplete), implode(', ', $incomplete)),
      sprintf('%d of %d module(s) incomplete', count($incomplete), $total),
      'All modules have complete metadata',
      $details,
    );
  }

}
