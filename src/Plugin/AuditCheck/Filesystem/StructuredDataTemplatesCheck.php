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
 * Scans custom themes for .jsonld or schema.json files and checks the metatag module.
 */
#[AuditCheck(
  id: 'fs_structured_data',
  label: new TranslatableMarkup('Structured Data Templates'),
  description: new TranslatableMarkup('Scans custom themes for .jsonld or schema.json files and checks the metatag module.'),
  scope: 'site',
  category: 'AI Signals',
)]
class StructuredDataTemplatesCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

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
   *
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
      return $this->pass(
        sprintf('%d structured-data template file(s) found in custom themes. This supports rich search results and AI-readiness.', $jsonldCount),
        sprintf('%d file(s) found', $jsonldCount),
        'Structured data templates present',
        $details,
      );
    }

    if ($metatagInstalled) {
      return $this->info(
        'No JSON-LD template files were found, but the metatag module is installed and can output structured data via configuration.',
        'No templates; metatag installed',
        'Structured data templates present',
        $details,
      );
    }

    return $this->info(
      'No structured data template files or metatag module detected. Consider implementing JSON-LD structured data for improved AI and search engine readiness.',
      'None found',
      'Structured data templates or metatag module',
      $details,
    );
  }

}
