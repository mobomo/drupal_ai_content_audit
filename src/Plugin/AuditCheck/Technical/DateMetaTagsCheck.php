<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Technical;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase;
use Drupal\ai_content_audit\Service\HtmlFetchService;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks for article:published_time and article:modified_time Open Graph meta tags in ISO 8601 format.
 */
#[AuditCheck(
  id: 'date_meta_tags',
  label: new TranslatableMarkup('Date Meta Tags'),
  description: new TranslatableMarkup('Checks for article:published_time and article:modified_time Open Graph meta tags in ISO 8601 format.'),
  scope: 'node',
  category: 'Technical',
)]
class DateMetaTagsCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
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
      $container->get('ai_content_audit.html_fetch'),
      $container->get('logger.factory')->get('ai_content_audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    // Resolve URL (node canonical or homepage).
    $url = NULL;
    try {
      if ($node !== NULL) {
        $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()])
          ->setAbsolute()
          ->toString();
      }
      else {
        $url = $this->htmlFetch->getBaseUrl() . '/';
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Technical audit: date meta tags URL resolution failed: @msg', ['@msg' => $e->getMessage()]);
    }

    $html = ($url !== NULL) ? $this->htmlFetch->fetchPageHtml($url) : NULL;

    if ($html === NULL) {
      return $this->info(
        'Could not fetch page HTML to check Open Graph date meta tags.',
        NULL,
        NULL,
        [
          'has_published_time' => FALSE,
          'published_time_value' => NULL,
          'published_time_valid' => FALSE,
          'has_modified_time' => FALSE,
          'modified_time_value' => NULL,
          'modified_time_valid' => FALSE,
        ],
      );
    }

    $iso8601Regex = '/^\d{4}-\d{2}-\d{2}/';

    // Check article:published_time.
    $hasPublishedTime = FALSE;
    $publishedTimeValue = NULL;
    $publishedTimeValid = FALSE;
    if (preg_match(
      '/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\']/i',
      $html,
      $pubMatch
    ) || preg_match(
      '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']article:published_time["\']/i',
      $html,
      $pubMatch
    )) {
      $hasPublishedTime = TRUE;
      $publishedTimeValue = $pubMatch[1];
      $publishedTimeValid = (bool) preg_match($iso8601Regex, $publishedTimeValue);
    }

    // Check article:modified_time.
    $hasModifiedTime = FALSE;
    $modifiedTimeValue = NULL;
    $modifiedTimeValid = FALSE;
    if (preg_match(
      '/<meta[^>]+property=["\']article:modified_time["\'][^>]+content=["\']([^"\']+)["\']/i',
      $html,
      $modMatch
    ) || preg_match(
      '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']article:modified_time["\']/i',
      $html,
      $modMatch
    )) {
      $hasModifiedTime = TRUE;
      $modifiedTimeValue = $modMatch[1];
      $modifiedTimeValid = (bool) preg_match($iso8601Regex, $modifiedTimeValue);
    }

    $details = [
      'has_published_time' => $hasPublishedTime,
      'published_time_value' => $publishedTimeValue,
      'published_time_valid' => $publishedTimeValid,
      'has_modified_time' => $hasModifiedTime,
      'modified_time_value' => $modifiedTimeValue,
      'modified_time_valid' => $modifiedTimeValid,
    ];

    // Determine status.
    if ($hasPublishedTime && $publishedTimeValid && $hasModifiedTime && $modifiedTimeValid) {
      return $this->pass(
        'Both article:published_time and article:modified_time are present with valid ISO 8601 values.',
        $publishedTimeValue,
        NULL,
        $details,
      );
    }

    if ($hasPublishedTime && $publishedTimeValid) {
      return $this->warning(
        'article:published_time is present but article:modified_time is missing. Adding a modified time helps LLMs assess content freshness.',
        $publishedTimeValue,
        NULL,
        $details,
      );
    }

    if ($hasPublishedTime) {
      return $this->warning(
        'article:published_time is present but the value "' . $publishedTimeValue . '" does not match ISO 8601 format.',
        $publishedTimeValue,
        NULL,
        $details,
      );
    }

    return $this->info(
      'No Open Graph article date meta tags found. These provide temporal context for LLMs and social media scrapers.',
      $publishedTimeValue,
      NULL,
      $details,
    );
  }

}
