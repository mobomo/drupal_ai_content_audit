<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Plugin\AuditCheck\Technical;

use Drupal\ai_content_audit_scoring\Attribute\AuditCheck;
use Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckBase;
use Drupal\ai_content_audit\Service\HtmlFetchService;
use Drupal\ai_content_audit_scoring\ValueObject\TechnicalAuditResult;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Verifies robots.txt, Sitemap directive, and blocks major AI bots.
 */
#[AuditCheck(
  id: 'robots_txt',
  label: new TranslatableMarkup('Robots.txt'),
  description: new TranslatableMarkup('Verifies robots.txt is present, has a Sitemap directive, and blocks major AI bots.'),
  scope: 'site',
  category: 'AI Signals',
)]
class RobotsTxtCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly HtmlFetchService $htmlFetch,
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
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
      $container->get('ai_content_audit.html_fetch'),
      $container->get('http_client'),
      $container->get('logger.factory')->get('ai_content_audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    try {
      $url = $this->htmlFetch->getBaseUrl() . '/robots.txt';
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);

      $statusCode = $response->getStatusCode();
      if ($statusCode !== 200) {
        return $this->fail(
          'robots.txt not found or not accessible (HTTP ' . $statusCode . ').',
          NULL,
          $this->generateRecommendedRobotsTxt(),
        );
      }

      $content = (string) $response->getBody();
      $hasSitemap = stripos($content, 'Sitemap:') !== FALSE;
      $hasLlmsAllow = stripos($content, 'llms.txt') !== FALSE;
      $blocksAiBots = preg_match('/User-agent:\s*(GPTBot|ChatGPT-User|Google-Extended|Anthropic|ClaudeBot|CCBot).*?Disallow:\s*\//si', $content);

      $issues = [];
      if (!$hasSitemap) {
        $issues[] = 'Missing Sitemap directive';
      }
      if ($blocksAiBots) {
        $issues[] = 'AI bot crawlers are currently blocked';
      }

      $status = empty($issues) ? 'pass' : 'warning';

      if ($status === 'pass') {
        return $this->pass(
          'robots.txt is properly configured for AI accessibility.',
          $content,
          $this->generateRecommendedRobotsTxt(),
          [
            'has_sitemap' => $hasSitemap,
            'has_llms_allow' => $hasLlmsAllow,
            'blocks_ai_bots' => (bool) $blocksAiBots,
          ],
        );
      }

      return $this->warning(
        'robots.txt found but needs improvements: ' . implode('; ', $issues) . '.',
        $content,
        $this->generateRecommendedRobotsTxt(),
        [
          'has_sitemap' => $hasSitemap,
          'has_llms_allow' => $hasLlmsAllow,
          'blocks_ai_bots' => (bool) $blocksAiBots,
        ],
      );
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Technical audit: robots.txt check failed: @msg', ['@msg' => $e->getMessage()]);
      return $this->fail(
        'Could not access robots.txt: ' . $e->getMessage(),
        NULL,
        $this->generateRecommendedRobotsTxt(),
      );
    }
  }

  /**
   * Generates recommended robots.txt content.
   */
  private function generateRecommendedRobotsTxt(): string {
    $baseUrl = $this->htmlFetch->getBaseUrl();
    return <<<TXT
# robots.txt - AI-friendly configuration
User-agent: *
Allow: /

# AI Crawlers
User-agent: GPTBot
Allow: /

User-agent: ChatGPT-User
Allow: /

User-agent: Google-Extended
Allow: /

User-agent: Anthropic
Allow: /

User-agent: ClaudeBot
Allow: /

# Sitemap
Sitemap: {$baseUrl}/sitemap.xml

# LLMs.txt
# See: {$baseUrl}/llms.txt
TXT;
  }

}
