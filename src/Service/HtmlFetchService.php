<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides shared HTTP page fetching with in-memory deduplication cache.
 *
 * This service extracts the fetchPageHtml() / getBaseUrl() logic that was
 * previously duplicated across TechnicalAuditService so that AuditCheck
 * plugins that need to inspect live HTML can share a single request per URL
 * within a single audit run.
 *
 * Inject '@ai_content_audit.html_fetch' into any plugin that needs HTTP-based
 * HTML inspection.
 *
 * @see \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase
 */
class HtmlFetchService {

  /**
   * In-memory HTML cache keyed by absolute URL.
   *
   * A NULL value means the fetch was attempted but failed; this prevents
   * repeated requests for the same URL in a single request cycle.
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
   * Fetches the HTML body of a page at the given absolute URL.
   *
   * Results are cached in memory for the lifetime of this service instance so
   * that multiple plugins inspecting the same URL within a single audit run
   * only issue one HTTP request.
   *
   * @param string $url
   *   The absolute URL to fetch.
   * @param string $cookieHeader
   *   Optional raw Cookie header string (e.g. "SESS...=abc; other=val") used
   *   to forward an authenticated session so that access-controlled or draft
   *   pages are returned in full rather than as a login redirect or 403.
   *
   * @return string|null
   *   The HTML body string, or NULL if the request failed or returned an error.
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
   * Returns the base URL of the current request (scheme + host).
   *
   * Falls back to 'http://localhost' when no current request is available
   * (e.g. during CLI drush commands).
   *
   * @return string
   *   The base URL, e.g. 'https://example.com'.
   */
  public function getBaseUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      return $request->getSchemeAndHttpHost();
    }
    // Fallback for CLI / test contexts.
    return 'http://localhost';
  }

  /**
   * Clears the in-memory HTML cache.
   *
   * Useful in long-running CLI batches where the same service instance is
   * reused across multiple audit runs.
   */
  public function clearCache(): void {
    $this->htmlCache = [];
  }

}
