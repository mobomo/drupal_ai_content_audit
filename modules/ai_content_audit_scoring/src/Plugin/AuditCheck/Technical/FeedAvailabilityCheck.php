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
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Probes RSS/Atom/JSON feeds and homepage alternate link tags.
 */
#[AuditCheck(
  id: 'feed_availability',
  label: new TranslatableMarkup('Feed Availability'),
  description: new TranslatableMarkup('Probes RSS/Atom/JSON Feed endpoints and checks homepage HTML for alternate link tags.'),
  scope: 'site',
  category: 'AI Signals',
)]
class FeedAvailabilityCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

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
    $probePaths = ['/rss.xml', '/feed', '/node/feed', '/feed.json'];
    $feedsFound = [];
    $probeError = FALSE;

    foreach ($probePaths as $path) {
      $url = $this->htmlFetch->getBaseUrl() . $path;
      try {
        $response = $this->httpClient->request('HEAD', $url, [
          'timeout' => 5,
          'http_errors' => FALSE,
        ]);
        if ($response->getStatusCode() === 200) {
          $feedsFound[] = $path;
        }
      }
      catch (\Exception $e) {
        $probeError = TRUE;
        $this->logger->debug('Technical audit: feed probe failed for @path: @msg', [
          '@path' => $path,
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    // Inspect homepage HTML for <link rel="alternate"> feed tags.
    $htmlLinkTagsFound = 0;
    $hasRss = FALSE;
    $hasAtom = FALSE;
    $hasJsonFeed = FALSE;

    $homepageUrl = $this->htmlFetch->getBaseUrl() . '/';
    $html = $this->htmlFetch->fetchPageHtml($homepageUrl);
    if ($html !== NULL) {
      // RSS alternate links.
      if (preg_match_all(
        '/<link[^>]+rel=["\']alternate["\'][^>]+type=["\']application\/rss\+xml["\'][^>]*\/?>/i',
        $html,
        $rssMatches
      )) {
        $count = count($rssMatches[0]);
        if ($count > 0) {
          $hasRss = TRUE;
          $htmlLinkTagsFound += $count;
        }
      }
      // Atom alternate links.
      if (preg_match_all(
        '/<link[^>]+rel=["\']alternate["\'][^>]+type=["\']application\/atom\+xml["\'][^>]*\/?>/i',
        $html,
        $atomMatches
      )) {
        $count = count($atomMatches[0]);
        if ($count > 0) {
          $hasAtom = TRUE;
          $htmlLinkTagsFound += $count;
        }
      }
      // JSON Feed alternate links.
      if (preg_match_all(
        '/<link[^>]+rel=["\']alternate["\'][^>]+type=["\']application\/feed\+json["\'][^>]*\/?>/i',
        $html,
        $jsonFeedMatches
      )) {
        $count = count($jsonFeedMatches[0]);
        if ($count > 0) {
          $hasJsonFeed = TRUE;
          $htmlLinkTagsFound += $count;
        }
      }
    }

    $feedCount = count($feedsFound) + $htmlLinkTagsFound;

    $details = [
      'feeds_found' => $feedsFound,
      'feed_count' => $feedCount,
      'has_rss' => $hasRss || in_array('/rss.xml', $feedsFound, TRUE) || in_array('/node/feed', $feedsFound, TRUE),
      'has_atom' => $hasAtom,
      'has_json_feed' => $hasJsonFeed || in_array('/feed.json', $feedsFound, TRUE),
      'html_link_tags_found' => $htmlLinkTagsFound,
    ];

    if ($probeError && $feedCount === 0) {
      return $this->warning(
        'Feed availability check encountered network errors and no feeds could be confirmed.',
        NULL,
        NULL,
        $details,
      );
    }

    if ($feedCount >= 1) {
      return $this->pass(
        'Found ' . $feedCount . ' feed(s): ' . implode(', ', $feedsFound) . ($htmlLinkTagsFound > 0 ? ' plus ' . $htmlLinkTagsFound . ' HTML link tag(s).' : '.'),
        NULL,
        NULL,
        $details,
      );
    }

    return $this->info(
      'No RSS, Atom, or JSON feeds detected. Adding feeds improves LLM incremental content discovery.',
      NULL,
      NULL,
      $details,
    );
  }

}
