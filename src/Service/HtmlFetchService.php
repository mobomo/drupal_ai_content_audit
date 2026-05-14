<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Fetches remote HTML with per-request in-memory caching.
 *
 * @see \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase
 */
class HtmlFetchService {

  /**
   * In-memory cache keyed by URL (and auth flag); NULL means fetch failed.
   *
   * @var array<string, string|null>
   */
  protected array $htmlCache = [];

  /**
   * Constructs an HtmlFetchService.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, used to derive the site base URL.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Fetches page HTML (GET), optionally with a Cookie header.
   *
   * @param string $url
   *   Absolute URL.
   * @param string $cookieHeader
   *   Optional Cookie header for authenticated or draft content.
   *
   * @return string|null
   *   Response body, or NULL on failure.
   */
  public function fetchPageHtml(string $url, string $cookieHeader = ''): ?string {
    $cacheKey = $url . ($cookieHeader ? '|auth' : '');
    if (array_key_exists($cacheKey, $this->htmlCache)) {
      return $this->htmlCache[$cacheKey];
    }

    $headers = ['User-Agent' => 'DrupalAiContentAudit/1.0'];
    if ($cookieHeader !== '') {
      $headers['Cookie'] = $cookieHeader;
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
        'headers' => $headers,
        'http_errors' => FALSE,
        'allow_redirects' => ['max' => 5],
      ]);
      $status = $response->getStatusCode();
      // Only accept 2xx responses; 3xx redirects are followed automatically,
      // 4xx/5xx mean the page is inaccessible.
      $html = $status >= 200 && $status < 300 ? (string) $response->getBody() : NULL;
      $this->htmlCache[$cacheKey] = $html;
      return $html;
    }
    catch (\Exception $e) {
      $this->htmlCache[$cacheKey] = NULL;
      return NULL;
    }
  }

  /**
   * Returns the current request base URL (scheme + host).
   *
   * @return string
   *   Base URL, or http://localhost when there is no request.
   */
  public function getBaseUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      return $request->getSchemeAndHttpHost();
    }
    // CLI / tests.
    return 'http://localhost';
  }

  /**
   * Clears the in-memory HTML cache.
   */
  public function clearCache(): void {
    $this->htmlCache = [];
  }

}
