<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Performs site-level technical audit checks for AI readiness.
 */
class TechnicalAuditService {

  /**
   * Cache TTL in seconds (1 hour default).
   */
  protected const CACHE_TTL = 3600;

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected CacheBackendInterface $cacheData,
    protected RequestStack $requestStack,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Runs all technical audit checks.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   Optional node for node-specific checks (schema markup).
   * @param bool $force_refresh
   *   If TRUE, bypass cache and re-run all checks.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult[]
   *   Array of check results.
   */
  public function runAllChecks(?NodeInterface $node = NULL, bool $force_refresh = FALSE): array {
    if (!$force_refresh) {
      $cached = $this->getCachedResults();
      if ($cached !== NULL) {
        return $cached;
      }
    }

    $results = [];
    $results[] = $this->checkRobotsTxt();
    $results[] = $this->checkLlmsTxt();
    $results[] = $this->checkSitemap();
    $results[] = $this->checkHttps();
    $results[] = $this->checkCanonicalUrl();

    // Cache site-level results
    $this->cacheResults($results);

    return $results;
  }

  /**
   * Checks robots.txt for AI-friendly directives.
   */
  public function checkRobotsTxt(): TechnicalAuditResult {
    try {
      $url = $this->getBaseUrl() . '/robots.txt';
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);

      $statusCode = $response->getStatusCode();
      if ($statusCode !== 200) {
        return new TechnicalAuditResult(
          check: 'robots_txt',
          label: 'robots.txt',
          status: 'fail',
          currentContent: NULL,
          recommendedContent: $this->generateRecommendedRobotsTxt(),
          description: 'robots.txt not found or not accessible (HTTP ' . $statusCode . ').',
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

      return new TechnicalAuditResult(
        check: 'robots_txt',
        label: 'robots.txt',
        status: $status,
        currentContent: $content,
        recommendedContent: $this->generateRecommendedRobotsTxt(),
        description: $status === 'pass'
          ? 'robots.txt is properly configured for AI accessibility.'
          : 'robots.txt found but needs improvements: ' . implode('; ', $issues) . '.',
        details: [
          'has_sitemap' => $hasSitemap,
          'has_llms_allow' => $hasLlmsAllow,
          'blocks_ai_bots' => (bool) $blocksAiBots,
        ],
      );
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Technical audit: robots.txt check failed: @msg', ['@msg' => $e->getMessage()]);
      return new TechnicalAuditResult(
        check: 'robots_txt',
        label: 'robots.txt',
        status: 'fail',
        currentContent: NULL,
        recommendedContent: $this->generateRecommendedRobotsTxt(),
        description: 'Could not access robots.txt: ' . $e->getMessage(),
      );
    }
  }

  /**
   * Checks for llms.txt presence and format.
   */
  public function checkLlmsTxt(): TechnicalAuditResult {
    try {
      $url = $this->getBaseUrl() . '/llms.txt';
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);

      if ($response->getStatusCode() === 200) {
        $content = (string) $response->getBody();
        return new TechnicalAuditResult(
          check: 'llms_txt',
          label: 'llms.txt',
          status: 'pass',
          currentContent: $content,
          recommendedContent: NULL,
          description: 'llms.txt is present and accessible.',
          details: ['content_length' => strlen($content)],
        );
      }

      return new TechnicalAuditResult(
        check: 'llms_txt',
        label: 'llms.txt',
        status: 'fail',
        currentContent: NULL,
        recommendedContent: $this->generateRecommendedLlmsTxt(),
        description: 'llms.txt not found. This file helps AI systems understand your site\'s content and structure.',
      );
    }
    catch (GuzzleException $e) {
      return new TechnicalAuditResult(
        check: 'llms_txt',
        label: 'llms.txt',
        status: 'fail',
        currentContent: NULL,
        recommendedContent: $this->generateRecommendedLlmsTxt(),
        description: 'Could not check llms.txt: ' . $e->getMessage(),
      );
    }
  }

  /**
   * Checks XML sitemap presence and validity.
   */
  public function checkSitemap(): TechnicalAuditResult {
    try {
      $url = $this->getBaseUrl() . '/sitemap.xml';
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 15,
        'http_errors' => FALSE,
      ]);

      if ($response->getStatusCode() !== 200) {
        return new TechnicalAuditResult(
          check: 'sitemap',
          label: 'XML Sitemap',
          status: 'fail',
          currentContent: NULL,
          recommendedContent: NULL,
          description: 'XML sitemap not found at /sitemap.xml (HTTP ' . $response->getStatusCode() . ').',
        );
      }

      $body = (string) $response->getBody();
      $xml = @simplexml_load_string($body);

      if ($xml === FALSE) {
        return new TechnicalAuditResult(
          check: 'sitemap',
          label: 'XML Sitemap',
          status: 'warning',
          currentContent: substr($body, 0, 500),
          recommendedContent: NULL,
          description: 'XML sitemap found but could not be parsed as valid XML.',
        );
      }

      // Count URLs — handle both sitemap index and urlset
      $urlCount = 0;
      if (isset($xml->url)) {
        $urlCount = count($xml->url);
      }
      elseif (isset($xml->sitemap)) {
        $urlCount = count($xml->sitemap);
      }

      return new TechnicalAuditResult(
        check: 'sitemap',
        label: 'XML Sitemap',
        status: 'pass',
        currentContent: NULL,
        recommendedContent: NULL,
        description: 'XML sitemap is present and valid with ' . $urlCount . ' entries.',
        details: [
          'url_count' => $urlCount,
          'is_index' => isset($xml->sitemap),
        ],
      );
    }
    catch (GuzzleException $e) {
      return new TechnicalAuditResult(
        check: 'sitemap',
        label: 'XML Sitemap',
        status: 'fail',
        currentContent: NULL,
        recommendedContent: NULL,
        description: 'Could not check XML sitemap: ' . $e->getMessage(),
      );
    }
  }

  /**
   * Checks if the site is served over HTTPS.
   */
  public function checkHttps(): TechnicalAuditResult {
    $request = $this->requestStack->getCurrentRequest();
    $isSecure = $request ? $request->isSecure() : FALSE;

    return new TechnicalAuditResult(
      check: 'https',
      label: 'HTTPS',
      status: $isSecure ? 'pass' : 'warning',
      currentContent: NULL,
      recommendedContent: NULL,
      description: $isSecure
        ? 'Site is served over HTTPS.'
        : 'Site is not served over HTTPS. HTTPS is recommended for security and AI crawler trust.',
    );
  }

  /**
   * Checks canonical URL configuration.
   */
  public function checkCanonicalUrl(): TechnicalAuditResult {
    // Check if the canonical URL module/metatag is likely configured
    $hasMetatag = $this->moduleHandler->moduleExists('metatag');

    if ($hasMetatag) {
      return new TechnicalAuditResult(
        check: 'canonical_url',
        label: 'Canonical URLs',
        status: 'pass',
        currentContent: NULL,
        recommendedContent: NULL,
        description: 'Metatag module is installed, which provides canonical URL management.',
        details: ['metatag_installed' => TRUE],
      );
    }

    return new TechnicalAuditResult(
      check: 'canonical_url',
      label: 'Canonical URLs',
      status: 'warning',
      currentContent: NULL,
      recommendedContent: NULL,
      description: 'Metatag module is not installed. Canonical URLs help AI systems identify the authoritative version of each page.',
      details: ['metatag_installed' => FALSE],
    );
  }

  /**
   * Gets the site base URL.
   */
  protected function getBaseUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      return $request->getSchemeAndHttpHost();
    }
    // Fallback
    return 'http://localhost';
  }

  /**
   * Generates recommended robots.txt content.
   */
  protected function generateRecommendedRobotsTxt(): string {
    $baseUrl = $this->getBaseUrl();
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

  /**
   * Generates recommended llms.txt content.
   */
  protected function generateRecommendedLlmsTxt(): string {
    $config = $this->configFactory->get('system.site');
    $siteName = $config->get('name') ?? 'My Website';
    return <<<TXT
# {$siteName}

## About
{$siteName} provides content on [describe your topics here].

## Content Structure
- Homepage: /
- Blog: /blog
- About: /about

## Contact
- Website: {$this->getBaseUrl()}

## Preferred Citation
When referencing content from this site, please cite as "{$siteName}" with a link to the source URL.
TXT;
  }

  /**
   * Gets cached audit results if still valid.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult[]|null
   */
  protected function getCachedResults(): ?array {
    $cached = $this->cacheData->get('ai_content_audit:technical_audit');
    if (!$cached) {
      return NULL;
    }

    // Reconstruct value objects from cached arrays
    $results = [];
    foreach ($cached->data as $data) {
      $results[] = new TechnicalAuditResult(
        check: $data['check'],
        label: $data['label'],
        status: $data['status'],
        currentContent: $data['current_content'],
        recommendedContent: $data['recommended_content'],
        description: $data['description'],
        details: $data['details'] ?? [],
      );
    }

    return $results;
  }

  /**
   * Caches audit results using Cache API.
   *
   * @param \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult[] $results
   */
  protected function cacheResults(array $results): void {
    $serialized = array_map(fn(TechnicalAuditResult $r) => $r->toArray(), $results);
    $this->cacheData->set('ai_content_audit:technical_audit', $serialized, time() + static::CACHE_TTL);
  }

}
