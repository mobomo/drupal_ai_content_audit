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
 * Checks robots.txt exists and determines if it is the default Drupal version or customised.
 */
#[AuditCheck(
  id: 'fs_robots_txt_disk',
  label: new TranslatableMarkup('Robots.txt On Disk'),
  description: new TranslatableMarkup('Checks robots.txt exists and determines if it is the default Drupal version or customised.'),
  scope: 'site',
  category: 'AI Signals',
)]
class RobotsTxtOnDiskCheck extends FilesystemCheckBase implements ContainerFactoryPluginInterface {

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
    $path = $this->safePath('robots.txt');
    $exists = $path !== NULL && file_exists($path);

    if (!$exists) {
      return $this->fail(
        'robots.txt is missing from the webroot. Search engines and crawlers will have no crawl instructions.',
        'Missing',
        'Present with User-agent directives',
        ['exists' => FALSE, 'has_user_agent' => FALSE, 'appears_customized' => FALSE],
      );
    }

    $raw = file_get_contents($path, length: 65536);
    $hasUserAgent = $raw !== FALSE && (bool) preg_match('/User-agent\s*:/i', $raw);
    $appearsDefault = $raw !== FALSE
      && (bool) preg_match('/^#\s*robots\.txt/i', $raw)
      && !(bool) preg_match('/Sitemap\s*:/i', $raw);
    $appearsCustomized = !$appearsDefault;
    unset($raw);

    $details = [
      'exists' => TRUE,
      'has_user_agent' => $hasUserAgent,
      'appears_customized' => $appearsCustomized,
    ];

    if ($hasUserAgent && $appearsCustomized) {
      return $this->pass(
        'robots.txt is present and appears to contain custom crawl directives.',
        'Present and customized',
        'Present with User-agent directives',
        $details,
      );
    }

    return $this->warning(
      'robots.txt appears to be the default Drupal file without customisation. Consider adding a Sitemap directive and environment-specific rules.',
      'Present (default)',
      'Customized with Sitemap and environment-specific rules',
      $details,
    );
  }

}
