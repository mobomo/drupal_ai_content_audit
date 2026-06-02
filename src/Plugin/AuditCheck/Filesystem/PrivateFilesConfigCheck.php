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
 * Verifies path.private config exists, is outside webroot, and is writable.
 */
#[AuditCheck(
  id: 'fs_private_files',
  label: new TranslatableMarkup('Private Files Configuration'),
  description: new TranslatableMarkup('Verifies path.private config exists, is outside webroot, and is writable.'),
  scope: 'site',
  category: 'Security',
)]
class PrivateFilesConfigCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

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
    $privatePath = $this->configFactory->get('system.file')->get('path.private');

    if (empty($privatePath)) {
      return $this->info(
        'No private files directory is configured. Sensitive files cannot be stored outside the public webroot.',
        'Not configured',
        'Configured outside webroot',
        ['configured' => FALSE, 'outside_webroot' => FALSE, 'writable' => FALSE],
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
      return $this->fail(
        'The private files directory is inside the webroot. Files stored here may be publicly accessible via HTTP.',
        '…' . $pathHint,
        'Path outside webroot',
        $details,
      );
    }

    if (!$writable) {
      return $this->fail(
        'The private files directory is outside the webroot but does not exist or is not writable by the web server.',
        '…' . $pathHint,
        'Writable directory outside webroot',
        $details,
      );
    }

    return $this->pass(
      'The private files directory is configured outside the webroot and is writable.',
      '…' . $pathHint,
      'Writable directory outside webroot',
      $details,
    );
  }

}
