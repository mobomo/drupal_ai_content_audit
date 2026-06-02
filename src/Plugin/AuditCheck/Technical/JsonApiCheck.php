<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Technical;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase;
use Drupal\ai_content_audit\Service\HtmlFetchService;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks if the jsonapi module is enabled and the /jsonapi endpoint responds.
 */
#[AuditCheck(
  id: 'json_api',
  label: new TranslatableMarkup('JSON:API'),
  description: new TranslatableMarkup('Checks if the jsonapi module is enabled and the /jsonapi endpoint responds.'),
  scope: 'site',
  category: 'AI Signals',
)]
class JsonApiCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly ClientInterface $httpClient,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly HtmlFetchService $htmlFetch,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   *
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('module_handler'),
      $container->get('ai_content_audit.html_fetch'),
      $container->get('logger.factory')->get('ai_content_audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $moduleInstalled = $this->moduleHandler->moduleExists('jsonapi');
    $endpointAccessible = FALSE;

    if ($moduleInstalled) {
      $url = $this->htmlFetch->getBaseUrl() . '/jsonapi';
      try {
        $response = $this->httpClient->request('HEAD', $url, [
          'timeout' => 5,
          'http_errors' => FALSE,
        ]);
        // Accept 200 or 415 (Unsupported Media Type — still means endpoint exists).
        $endpointAccessible = in_array($response->getStatusCode(), [200, 415], TRUE);
      }
      catch (\Exception $e) {
        $this->logger->debug('Technical audit: JSON:API endpoint probe failed: @msg', ['@msg' => $e->getMessage()]);
      }
    }

    $details = [
      'module_installed' => $moduleInstalled,
      'endpoint_accessible' => $endpointAccessible,
    ];

    if ($moduleInstalled && $endpointAccessible) {
      return $this->pass(
        'JSON:API module is enabled and the /jsonapi endpoint is accessible. This provides machine-readable content access for LLM RAG systems.',
        NULL,
        NULL,
        $details,
      );
    }

    if ($moduleInstalled) {
      return $this->pass(
        'JSON:API module is enabled. The /jsonapi endpoint could not be confirmed accessible, but the module is installed.',
        NULL,
        NULL,
        $details,
      );
    }

    return $this->info(
      'JSON:API module is not installed. Enabling it provides machine-readable RESTful endpoints useful for LLM RAG pipelines.',
      NULL,
      NULL,
      $details,
    );
  }

}
