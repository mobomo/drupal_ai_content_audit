<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Plugin\AuditCheck\Technical;

use Drupal\ai_content_audit_scoring\Attribute\AuditCheck;
use Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckBase;
use Drupal\ai_content_audit_scoring\ValueObject\TechnicalAuditResult;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Verifies the site is served over HTTPS.
 */
#[AuditCheck(
  id: 'https',
  label: new TranslatableMarkup('HTTPS'),
  description: new TranslatableMarkup('Verifies the site is served over HTTPS.'),
  scope: 'site',
  category: 'Technical',
)]
class HttpsCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $request = $this->requestStack->getCurrentRequest();
    $isSecure = $request ? $request->isSecure() : FALSE;

    if ($isSecure) {
      return $this->pass('Site is served over HTTPS.');
    }

    return $this->warning(
      'Site is not served over HTTPS. HTTPS is recommended for security and AI crawler trust.',
    );
  }

}
