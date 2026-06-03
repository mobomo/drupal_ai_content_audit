<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Technical;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase;
use Drupal\ai_content_audit\Service\HtmlFetchService;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks sitemap.xml exists with URLs, lastmod coverage, and index support.
 */
#[AuditCheck(
  id: 'sitemap',
  label: new TranslatableMarkup('XML Sitemap'),
  description: new TranslatableMarkup('Checks sitemap.xml exists, contains URLs, has lastmod coverage, and handles sitemap index.'),
  scope: 'site',
  category: 'AI Signals',
)]
class SitemapCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

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
      $url = $this->htmlFetch->getBaseUrl() . '/sitemap.xml';
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 15,
        'http_errors' => FALSE,
      ]);

      if ($response->getStatusCode() !== 200) {
        return $this->fail(
          'XML sitemap not found at /sitemap.xml (HTTP ' . $response->getStatusCode() . ').',
        );
      }

      $body = (string) $response->getBody();
      $xml = @simplexml_load_string($body);

      if ($xml === FALSE) {
        return $this->warning(
          'XML sitemap found but could not be parsed as valid XML.',
          substr($body, 0, 500),
        );
      }

      // Count URLs — handle both sitemap index and urlset.
      $urlCount = 0;
      $isIndex = isset($xml->sitemap);

      if (!$isIndex && isset($xml->url)) {
        $urlCount = count($xml->url);
      }
      elseif ($isIndex) {
        $urlCount = count($xml->sitemap);
      }

      // --- Measure lastmod and priority coverage ---
      $lastmodCount = 0;
      $priorityCount = 0;
      $coverageBase = 0;

      if (!$isIndex && isset($xml->url)) {
        // Regular urlset: inspect all <url> entries directly.
        $coverageBase = $urlCount;
        foreach ($xml->url as $urlElement) {
          if (isset($urlElement->lastmod)) {
            $lastmodCount++;
          }
          if (isset($urlElement->priority)) {
            $priorityCount++;
          }
        }
      }
      elseif ($isIndex && isset($xml->sitemap[0]->loc)) {
        // Sitemap index: sample the first child sitemap for coverage metrics.
        $childUrl = (string) $xml->sitemap[0]->loc;
        try {
          $childResponse = $this->httpClient->request('GET', $childUrl, [
            'timeout' => 15,
            'http_errors' => FALSE,
          ]);
          if ($childResponse->getStatusCode() === 200) {
            $childXml = @simplexml_load_string((string) $childResponse->getBody());
            if ($childXml !== FALSE && isset($childXml->url)) {
              $coverageBase = count($childXml->url);
              foreach ($childXml->url as $urlElement) {
                if (isset($urlElement->lastmod)) {
                  $lastmodCount++;
                }
                if (isset($urlElement->priority)) {
                  $priorityCount++;
                }
              }
            }
          }
        }
        catch (\Exception $e) {
          // Sampling failed; coverage metrics stay at zero.
          $this->logger->debug('Technical audit: sitemap index child fetch failed: @msg', ['@msg' => $e->getMessage()]);
        }
      }

      $lastmodCoveragePct = $coverageBase > 0
        ? round(($lastmodCount / $coverageBase) * 100, 1)
        : 0.0;
      $priorityCoveragePct = $coverageBase > 0
        ? round(($priorityCount / $coverageBase) * 100, 1)
        : 0.0;

      // --- Determine status ---
      // warning if lastmod coverage is below 50% on a regular urlset.
      $qualityWarning = (!$isIndex && $coverageBase > 0 && $lastmodCoveragePct < 50.0);
      $status = $qualityWarning ? 'warning' : 'pass';

      $description = 'XML sitemap is present and valid with ' . $urlCount . ' entries.';
      if ($qualityWarning) {
        $description .= ' Only ' . $lastmodCoveragePct . '% of URLs have a <lastmod> element; consider adding date metadata for better LLM temporal context.';
      }

      $details = [
        'url_count' => $urlCount,
        'is_index' => $isIndex,
        'lastmod_count' => $lastmodCount,
        'lastmod_coverage_pct' => $lastmodCoveragePct,
        'priority_count' => $priorityCount,
        'priority_coverage_pct' => $priorityCoveragePct,
      ];

      if ($status === 'pass') {
        return $this->pass($description, NULL, NULL, $details);
      }

      return $this->warning($description, NULL, NULL, $details);
    }
    catch (GuzzleException $e) {
      return $this->fail(
        'Could not check XML sitemap: ' . $e->getMessage(),
      );
    }
  }

}
