<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Verifies the public files directory exists and is writable.
 */
#[AuditCheck(
  id: 'fs_public_writable',
  label: new TranslatableMarkup('Public Files Writable'),
  description: new TranslatableMarkup('Verifies the public files directory exists and is writable.'),
  scope: 'site',
  category: 'Filesystem Health',
)]
class PublicFilesWritableCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    string $drupalRoot,
    private readonly ConfigFactoryInterface $configFactory,
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
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $configuredPath = $this->configFactory->get('system.file')->get('path.public') ?? 'sites/default/files';
    $fullPath = $this->drupalRoot . \DIRECTORY_SEPARATOR . ltrim((string) $configuredPath, '/\\');
    $writable = is_dir($fullPath) && is_writable($fullPath);

    $details = ['writable' => $writable];

    if ($writable) {
      return $this->pass(
        'The public files directory is writable by the web server.',
        'Writable',
        'Writable',
        $details,
      );
    }

    return $this->fail(
      'The public files directory is not writable. File uploads and managed file operations will fail.',
      'Not writable',
      'Writable',
      $details,
    );
  }

}
