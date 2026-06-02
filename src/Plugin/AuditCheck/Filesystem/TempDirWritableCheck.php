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
 * Verifies the temporary files directory is writable, falling back to sys_get_temp_dir().
 */
#[AuditCheck(
  id: 'fs_temp_writable',
  label: new TranslatableMarkup('Temp Directory Writable'),
  description: new TranslatableMarkup('Verifies the temporary files directory is writable, falling back to sys_get_temp_dir().'),
  scope: 'site',
  category: 'Filesystem Health',
)]
class TempDirWritableCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

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
    $tempPath = $this->configFactory->get('system.file')->get('path.temporary');

    if (empty($tempPath)) {
      $sysTemp = sys_get_temp_dir();
      $writable = is_writable($sysTemp);
      return $this->info(
        'No custom temporary directory is configured; using the system temp directory.',
        'System default',
        'Custom writable path',
        ['using_system_default' => TRUE, 'writable' => $writable],
      );
    }

    $writable = is_dir($tempPath) && is_writable($tempPath);
    $details = ['using_system_default' => FALSE, 'writable' => $writable];

    if ($writable) {
      return $this->pass(
        'The configured temporary directory is writable.',
        'Writable',
        'Writable',
        $details,
      );
    }

    return $this->warning(
      'The configured temporary directory is not writable. File processing operations that require a writable temp directory may fail.',
      'Not writable',
      'Writable',
      $details,
    );
  }

}
