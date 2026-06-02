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
 * Checks llms.txt exists at webroot and is non-empty.
 */
#[AuditCheck(
  id: 'fs_llms_txt_disk',
  label: new TranslatableMarkup('LLMs.txt On Disk'),
  description: new TranslatableMarkup('Checks llms.txt exists at webroot and is non-empty.'),
  scope: 'site',
  category: 'AI Signals',
)]
class LlmsTxtOnDiskCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

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
    $path = $this->safePath('llms.txt');
    $exists = $path !== NULL && file_exists($path);
    $sizeBytes = $exists ? (int) filesize($path) : 0;
    $isNonEmpty = $sizeBytes > 0;

    $details = ['size_bytes' => $sizeBytes, 'is_non_empty' => $isNonEmpty];

    if ($exists && $isNonEmpty) {
      return $this->pass(
        'llms.txt is present at the webroot and is non-empty.',
        'Present',
        'Present and non-empty',
        $details,
      );
    }

    return $this->fail(
      'llms.txt is missing or empty. Adding this file helps LLM crawlers understand site content and policies.',
      $exists ? 'Present but empty' : 'Missing',
      'Present and non-empty',
      $details,
    );
  }

}
