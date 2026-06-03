<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Technical;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase;
use Drupal\ai_content_audit\Service\HtmlFetchService;
use Drupal\ai_content_audit\Trait\SchemaMarkupParserTrait;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks for schema.org structured data with at least 3 of 11 desired @type values.
 */
#[AuditCheck(
  id: 'schema_markup',
  label: new TranslatableMarkup('Schema Markup'),
  description: new TranslatableMarkup('Checks for schema.org structured data with at least 3 of 11 desired @type values.'),
  scope: 'site',
  category: 'AI Signals',
)]
class SchemaMarkupCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

  use SchemaMarkupParserTrait;

  /**
   * Schema.org types that indicate good structured data coverage.
   */
  protected const DESIRED_SCHEMA_TYPES = [
    'Article',
    'NewsArticle',
    'BlogPosting',
    'WebPage',
    'WebSite',
    'Organization',
    'LocalBusiness',
    'BreadcrumbList',
    'Person',
    'Author',
    'FAQPage',
  ];

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly HtmlFetchService $htmlFetch,
    private readonly ModuleHandlerInterface $moduleHandler,
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
      $container->get('module_handler'),
      $container->get('logger.factory')->get('ai_content_audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    // Resolve the URL to inspect.
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
      $this->logger->warning('Technical audit: schema markup URL resolution failed: @msg', ['@msg' => $e->getMessage()]);
    }

    // Fetch HTML via shared in-memory cache.
    $html = ($url !== NULL) ? $this->htmlFetch->fetchPageHtml($url) : NULL;

    if ($html === NULL) {
      $this->logger->warning('Technical audit: schema markup check failed to fetch page.');
      $metatagSchemaInstalled = $this->moduleHandler->moduleExists('schema_metatag')
        || $this->moduleHandler->moduleExists('metatag_schema');
      return $this->warning(
        'Unable to fetch page HTML to inspect schema markup.',
        NULL,
        $this->generateSchemaRecommendation(),
        [
          'schema_types_found' => [],
          'total_scripts' => 0,
          'has_article' => FALSE,
          'has_breadcrumb' => FALSE,
          'has_organization' => FALSE,
          'has_webpage' => FALSE,
          'metatag_schema_installed' => $metatagSchemaInstalled,
          'checked_url' => $url,
          'article_has_date_published' => FALSE,
          'article_has_date_modified' => FALSE,
          'date_published_valid_format' => FALSE,
          'date_modified_valid_format' => FALSE,
        ],
      );
    }

    // Extract all <script type="application/ld+json"> blocks.
    $foundTypes = $this->extractSchemaTypes($html);
    $totalScripts = $this->countJsonLdScripts($html);

    // Categorise found types for details.
    $articleTypes = ['Article', 'NewsArticle', 'BlogPosting'];
    $webPageTypes = ['WebPage', 'WebSite'];
    $hasArticle = !empty(array_intersect($foundTypes, $articleTypes));
    $hasBreadcrumb = in_array('BreadcrumbList', $foundTypes, TRUE);
    $hasOrganization = !empty(array_intersect($foundTypes, ['Organization', 'LocalBusiness']));
    $hasWebPage = !empty(array_intersect($foundTypes, $webPageTypes));

    // For site-level checks, also note whether schema_metatag module is present.
    $metatagSchemaInstalled = $this->moduleHandler->moduleExists('schema_metatag')
      || $this->moduleHandler->moduleExists('metatag_schema');

    // Extract article date properties (does not affect pass/fail status).
    $dateProperties = $this->extractSchemaDateProperties($html);

    // Determine status: ≥3 distinct desired types = pass, 1-2 = warning, 0 = fail.
    $desiredFound = array_intersect($foundTypes, static::DESIRED_SCHEMA_TYPES);
    $desiredCount = count($desiredFound);

    $details = [
      'schema_types_found' => array_values($desiredFound),
      'total_scripts' => $totalScripts,
      'has_article' => $hasArticle,
      'has_breadcrumb' => $hasBreadcrumb,
      'has_organization' => $hasOrganization,
      'has_webpage' => $hasWebPage,
      'metatag_schema_installed' => $metatagSchemaInstalled,
      'checked_url' => $url,
      'article_has_date_published' => $dateProperties['article_has_date_published'],
      'article_has_date_modified' => $dateProperties['article_has_date_modified'],
      'date_published_valid_format' => $dateProperties['date_published_valid_format'],
      'date_modified_valid_format' => $dateProperties['date_modified_valid_format'],
    ];

    $currentContent = !empty($desiredFound)
      ? 'Found: ' . implode(', ', $desiredFound)
      : 'No schema types found';

    if ($desiredCount >= 3) {
      return $this->pass(
        'Strong Schema.org coverage: ' . $desiredCount . ' schema types found ('
          . implode(', ', $desiredFound) . ').',
        $currentContent,
        $this->generateSchemaRecommendation(),
        $details,
      );
    }

    if ($desiredCount >= 1) {
      return $this->warning(
        'Partial Schema.org coverage: only ' . $desiredCount . ' schema type(s) found ('
          . implode(', ', $desiredFound) . '). Aim for at least 3 types.',
        $currentContent,
        $this->generateSchemaRecommendation(),
        $details,
      );
    }

    $description = $totalScripts > 0
      ? 'JSON-LD scripts found but no recognised Schema.org types detected.'
      : 'No Schema.org structured data (application/ld+json) found on this page.';

    return $this->fail(
      $description,
      $currentContent,
      $this->generateSchemaRecommendation(),
      $details,
    );
  }

  /**
   * Generates a recommended Schema.org implementation summary.
   */
  private function generateSchemaRecommendation(): string {
    return <<<REC
Add Schema.org structured data to improve LLM discoverability. At minimum, include:

- Article (or WebPage/BlogPosting) — identifies content type
- Organization — identifies site ownership
- BreadcrumbList — provides navigation context
- Person (Author) — establishes authorship

Install the 'schema_metatag' contrib module for automatic JSON-LD generation,
or add manual markup via a custom theme or module using hook_page_attachments().
REC;
  }

}
