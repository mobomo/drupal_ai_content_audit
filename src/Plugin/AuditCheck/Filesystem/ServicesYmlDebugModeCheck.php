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
 * Checks services.yml or default.services.yml for Twig debug: true and auto_reload: true flags.
 */
#[AuditCheck(
  id: 'fs_services_debug',
  label: new TranslatableMarkup('Services YAML Debug Mode'),
  description: new TranslatableMarkup('Checks services.yml or default.services.yml for Twig debug: true and auto_reload: true flags.'),
  scope: 'site',
  category: 'Security',
)]
class ServicesYmlDebugModeCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

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
    // Prefer the active services.yml; fall back to default.
    $candidates = [
      'sites/default/services.yml',
      'sites/default/default.services.yml',
    ];

    $twigDebug = FALSE;
    $autoReload = FALSE;
    $checkedFile = NULL;

    foreach ($candidates as $relative) {
      $path = $this->safePath($relative);
      if ($path === NULL || !file_exists($path)) {
        continue;
      }

      $raw = file_get_contents($path, length: 65536);
      if ($raw === FALSE) {
        continue;
      }

      $twigDebug = $twigDebug || (bool) preg_match('/^\s*debug:\s*true\b/im', $raw);
      $autoReload = $autoReload || (bool) preg_match('/^\s*auto_reload:\s*true\b/im', $raw);
      unset($raw);
      $checkedFile = $relative;
      break;
    }

    $details = ['twig_debug_on' => $twigDebug, 'auto_reload_on' => $autoReload];

    if ($twigDebug || $autoReload) {
      return $this->warning(
        'Twig debug or auto_reload is enabled in services.yml. These settings expose template markup and increase cache-miss overhead; disable them in production.',
        'Debug flags enabled',
        'Debug flags disabled',
        $details,
      );
    }

    return $this->pass(
      $checkedFile !== NULL
        ? 'No Twig debug flags were detected in services.yml.'
        : 'No services.yml file was found; using Drupal defaults (debug off).',
      'No debug flags',
      'Debug flags disabled',
      $details,
    );
  }

}
