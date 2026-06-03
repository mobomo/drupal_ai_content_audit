<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai_content_audit\Plugin\Manager\AuditCheckManager;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
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

  /**
   * In-memory HTML fetch cache keyed by URL.
   *
   * @var array<string, string|null>
   */
  protected array $htmlCache = [];

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected CacheBackendInterface $cacheData,
    protected RequestStack $requestStack,
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AuditCheckManager $auditCheckManager,
  ) {}

  /**
   * Runs all technical audit checks.
   *
   * Site-level checks (robots_txt, llms_txt, sitemap, https) are cached.
   * Node-specific checks (schema_markup, entity_relationships) and the deep
   * canonical check are always run fresh when a node is provided.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   Optional node for node-specific checks (schema markup, entity
   *   relationships, deep canonical verification).
   * @param bool $force_refresh
   *   If TRUE, bypass cache and re-run all checks.
   *
   * @return array<string, \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult>
   *   Keyed array of check results indexed by check machine name.
   */
  public function runAllChecks(?NodeInterface $node = NULL, bool $force_refresh = FALSE): array {
    // Delegate to the AuditCheckManager plugin system.
    // Only run technical checks — filesystem checks use the 'fs_' ID prefix
    // and are handled exclusively by FilesystemAuditService.
    $results = [];

    foreach ($this->auditCheckManager->getDefinitions() as $id => $definition) {
      // Skip filesystem-scoped plugins; they belong to FilesystemAuditService.
      if (str_starts_with($id, 'fs_')) {
        continue;
      }

      try {
        /** @var \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckInterface $check */
        $check = $this->auditCheckManager->createInstance($id);

        if (!$check->applies($node)) {
          continue;
        }

        $results[$id] = $check->run($node);
      }
      catch (\Exception $e) {
        $this->logger->error(
          'TechnicalAuditService: check "@id" threw an exception: @msg',
          ['@id' => $id, '@msg' => $e->getMessage()],
        );
        $results[$id] = new TechnicalAuditResult(
          check: $id,
          label: $id,
          status: 'fail',
          currentContent: NULL,
          recommendedContent: NULL,
          description: sprintf('Check "%s" threw an exception: %s', $id, $e->getMessage()),
        );
      }
    }

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
   * Checks for llms.txt presence, format, and content structure.
   *
   * Validates the file against the llmstxt.org specification:
   * - Exactly one H1 heading (#) as the first heading
   * - A blockquote description (> ...) following the H1
   * - At least one H2 section (## ...)
   * - At least one linked resource (- [title](url))
   * Also checks for the optional companion /llms-full.txt file.
   */
  public function checkLlmsTxt(): TechnicalAuditResult {
    try {
      $url = $this->getBaseUrl() . '/llms.txt';
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);

      if ($response->getStatusCode() !== 200) {
        return new TechnicalAuditResult(
          check: 'llms_txt',
          label: 'llms.txt',
          status: 'fail',
          currentContent: NULL,
          recommendedContent: $this->generateRecommendedLlmsTxt(),
          description: 'llms.txt not found. This file helps AI systems understand your site\'s content and structure.',
        );
      }

      $content = (string) $response->getBody();
      $contentLength = strlen($content);

      // --- Parse content structure against llmstxt.org spec ---
      $lines = explode("\n", str_replace("\r\n", "\n", $content));

      $h1Count = 0;
      $h1Value = '';
      $hasBlockquote = FALSE;
      $h2SectionCount = 0;
      $linkCount = 0;
      $h1Found = FALSE;

      foreach ($lines as $line) {
        $trimmed = rtrim($line);

        // H1: line starting with '# ' (not '## ').
        if (preg_match('/^# .+/', $trimmed) && !preg_match('/^## /', $trimmed)) {
          $h1Count++;
          if ($h1Count === 1) {
            $h1Value = ltrim(substr($trimmed, 2));
            $h1Found = TRUE;
          }
          continue;
        }

        // H2 section: line starting with '## '.
        if (preg_match('/^## .+/', $trimmed)) {
          $h2SectionCount++;
          continue;
        }

        // Blockquote description after H1.
        if ($h1Found && !$hasBlockquote && preg_match('/^> .+/', $trimmed)) {
          $hasBlockquote = TRUE;
          continue;
        }

        // Linked resource: - [title](url) with optional ': description'.
        if (preg_match('/^- \[.+\]\(.+\)/', $trimmed)) {
          $linkCount++;
        }
      }

      // --- Collect validation issues ---
      $validationIssues = [];
      if ($h1Count === 0) {
        $validationIssues[] = 'Missing required H1 heading';
      }
      elseif ($h1Count > 1) {
        $validationIssues[] = 'Multiple H1 headings found';
      }
      if (!$hasBlockquote) {
        $validationIssues[] = 'Missing required blockquote description after H1';
      }
      if ($h2SectionCount === 0) {
        $validationIssues[] = 'No content sections (H2 headings) found';
      }
      if ($linkCount === 0) {
        $validationIssues[] = 'No linked resources found';
      }

      $structureValid = empty($validationIssues);

      // --- Check for companion llms-full.txt ---
      $hasCompanionFile = FALSE;
      try {
        $companionResponse = $this->httpClient->request('HEAD', $this->getBaseUrl() . '/llms-full.txt', [
          'timeout' => 5,
          'http_errors' => FALSE,
        ]);
        $hasCompanionFile = $companionResponse->getStatusCode() === 200;
      }
      catch (\Exception $e) {
        // Companion file check failed; treat as absent.
        $hasCompanionFile = FALSE;
      }

      // --- Determine status ---
      // pass = H1 + blockquote + ≥1 H2 + ≥1 link; warning = file exists but issues.
      $hasH1 = ($h1Count === 1);
      if ($hasH1 && $hasBlockquote && $h2SectionCount >= 1 && $linkCount >= 1) {
        $status = 'pass';
        $description = 'llms.txt is present, accessible, and structurally valid per the llmstxt.org specification.';
      }
      else {
        $status = 'warning';
        $description = 'llms.txt found but has structural issues: ' . implode('; ', $validationIssues) . '.';
      }

      return new TechnicalAuditResult(
        check: 'llms_txt',
        label: 'llms.txt',
        status: $status,
        currentContent: $content,
        recommendedContent: $structureValid ? NULL : $this->generateRecommendedLlmsTxt(),
        description: $description,
        details: [
          'content_length' => $contentLength,
          'has_h1' => $hasH1,
          'h1_value' => $h1Value,
          'has_blockquote' => $hasBlockquote,
          'h2_section_count' => $h2SectionCount,
          'link_count' => $linkCount,
          'has_companion_file' => $hasCompanionFile,
          'validation_issues' => $validationIssues,
          'structure_valid' => $structureValid,
        ],
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
   * Checks XML sitemap presence, validity, and quality attributes.
   *
   * In addition to the baseline URL count, measures temporal metadata coverage
   * (lastmod) and priority coverage across all <url> entries. For sitemap
   * index files, samples the first child sitemap to derive coverage metrics.
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

      return new TechnicalAuditResult(
        check: 'sitemap',
        label: 'XML Sitemap',
        status: $status,
        currentContent: NULL,
        recommendedContent: NULL,
        description: $description,
        details: [
          'url_count' => $urlCount,
          'is_index' => $isIndex,
          'lastmod_count' => $lastmodCount,
          'lastmod_coverage_pct' => $lastmodCoveragePct,
          'priority_count' => $priorityCount,
          'priority_coverage_pct' => $priorityCoveragePct,
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
   * Checks canonical URL configuration with deep live HTML verification.
   *
   * When a node is provided, fetches its rendered HTML and inspects the
   * actual <link rel="canonical"> tag output. Falls back to a module-presence
   * check when no node is given.
   *
   * Uses fetchPageHtml() to avoid redundant HTTP requests when the same URL
   * is also inspected by checkSchemaMarkup() in the same audit run.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   Optional node for per-page canonical verification.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   *   The audit check result.
   */
  public function checkCanonicalUrl(?NodeInterface $node = NULL): TechnicalAuditResult {
    // Phase 1: Check module availability (baseline check).
    $hasMetatag = $this->moduleHandler->moduleExists('metatag');

    // Phase 2: Resolve expected URL.
    $canonicalFound = FALSE;
    $canonicalValue = NULL;
    $canonicalValid = FALSE;
    $httpCheckFailed = FALSE;
    $expectedUrl = NULL;

    try {
      if ($node !== NULL) {
        $expectedUrl = Url::fromRoute('entity.node.canonical', ['node' => $node->id()])
          ->setAbsolute()
          ->toString();
      }
      else {
        $expectedUrl = $this->getBaseUrl() . '/';
      }
    }
    catch (\Exception $e) {
      $httpCheckFailed = TRUE;
      $this->logger->warning('Technical audit: canonical URL resolution failed: @msg', ['@msg' => $e->getMessage()]);
    }

    // Phase 3: Fetch page HTML via shared cache and inspect the canonical tag.
    if (!$httpCheckFailed && $expectedUrl !== NULL) {
      $html = $this->fetchPageHtml($expectedUrl);

      if ($html === NULL) {
        $httpCheckFailed = TRUE;
        $this->logger->warning('Technical audit: canonical URL live check failed: could not fetch @url.', ['@url' => $expectedUrl]);
      }
      else {
        // Parse <link rel="canonical" href="..."> — handle both attribute orders.
        if (preg_match(
          '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\'][^>]*\/?>/i',
          $html,
          $canonicalMatch
        ) || preg_match(
          '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\'][^>]*\/?>/i',
          $html,
          $canonicalMatch
        )) {
          $canonicalFound = TRUE;
          $canonicalValue = $canonicalMatch[1];
          // Validate the canonical points back to the expected URL.
          $canonicalValid = (rtrim($canonicalValue, '/') === rtrim($expectedUrl, '/'));
        }
      }
    }

    // Determine final status and description.
    if ($httpCheckFailed) {
      $status = $hasMetatag ? 'warning' : 'fail';
      $description = 'Could not perform live canonical check. '
        . ($hasMetatag ? 'Metatag module is installed.' : 'Metatag module is not installed.');
    }
    elseif ($canonicalFound && $canonicalValid) {
      $status = 'pass';
      $description = 'Canonical tag is present and points to the correct URL: ' . $canonicalValue;
    }
    elseif ($canonicalFound && !$canonicalValid) {
      $status = 'warning';
      $description = 'Canonical tag found but points to an unexpected URL: ' . $canonicalValue
        . '. Expected: ' . $expectedUrl;
    }
    else {
      // No canonical found.
      $status = $hasMetatag ? 'warning' : 'fail';
      $description = $hasMetatag
        ? 'Metatag module is installed but no <link rel="canonical"> found on the checked page. Verify per-content-type metatag configuration.'
        : 'No <link rel="canonical"> found and Metatag module is not installed. Canonical URLs help AI systems identify the authoritative version of each page.';
    }

    return new TechnicalAuditResult(
      check: 'canonical_url',
      label: 'Canonical URLs',
      status: $status,
      currentContent: $canonicalValue,
      recommendedContent: NULL,
      description: $description,
      details: [
        'module_installed' => $hasMetatag,
        'canonical_found' => $canonicalFound,
        'canonical_url' => $canonicalValue,
        'expected_url' => $expectedUrl,
        'canonical_valid' => $canonicalValid,
        'http_check_failed' => $httpCheckFailed,
      ],
    );
  }

  /**
   * Checks for Schema.org structured data markup in the rendered page HTML.
   *
   * Makes an HTTP request to the node's canonical URL (or the homepage for a
   * site-level check) and parses <script type="application/ld+json"> blocks,
   * validating the presence of key schema types recommended for LLM
   * discoverability.
   *
   * Also extracts article date properties (datePublished / dateModified) from
   * any Article, NewsArticle, or BlogPosting objects in the JSON-LD payload.
   * These date fields are included in details but do NOT affect pass/fail status.
   *
   * Uses fetchPageHtml() to avoid redundant HTTP requests when the same URL
   * is also inspected by checkCanonicalUrl() in the same audit run.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   Optional node to check. Falls back to the site homepage when NULL.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   *   The audit check result.
   */
  public function checkSchemaMarkup(?NodeInterface $node = NULL): TechnicalAuditResult {
    // Resolve the URL to inspect.
    $url = NULL;
    try {
      if ($node !== NULL) {
        $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()])
          ->setAbsolute()
          ->toString();
      }
      else {
        $url = $this->getBaseUrl() . '/';
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Technical audit: schema markup URL resolution failed: @msg', ['@msg' => $e->getMessage()]);
    }

    // Fetch HTML via shared in-memory cache.
    $html = ($url !== NULL) ? $this->fetchPageHtml($url) : NULL;

    if ($html === NULL) {
      $this->logger->warning('Technical audit: schema markup check failed to fetch page.');
      $metatagSchemaInstalled = $this->moduleHandler->moduleExists('schema_metatag')
        || $this->moduleHandler->moduleExists('metatag_schema');
      return new TechnicalAuditResult(
        check: 'schema_markup',
        label: 'Schema.org Markup',
        status: 'warning',
        currentContent: NULL,
        recommendedContent: $this->generateSchemaRecommendation(),
        description: 'Unable to fetch page HTML to inspect schema markup.',
        details: [
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

    if ($desiredCount >= 3) {
      $status = 'pass';
      $description = 'Strong Schema.org coverage: ' . $desiredCount . ' schema types found ('
        . implode(', ', $desiredFound) . ').';
    }
    elseif ($desiredCount >= 1) {
      $status = 'warning';
      $description = 'Partial Schema.org coverage: only ' . $desiredCount . ' schema type(s) found ('
        . implode(', ', $desiredFound) . '). Aim for at least 3 types.';
    }
    else {
      $status = 'fail';
      $description = $totalScripts > 0
        ? 'JSON-LD scripts found but no recognised Schema.org types detected.'
        : 'No Schema.org structured data (application/ld+json) found on this page.';
    }

    $currentContent = !empty($desiredFound)
      ? 'Found: ' . implode(', ', $desiredFound)
      : 'No schema types found';

    return new TechnicalAuditResult(
      check: 'schema_markup',
      label: 'Schema.org Markup',
      status: $status,
      currentContent: $currentContent,
      recommendedContent: $this->generateSchemaRecommendation(),
      description: $description,
      details: [
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
      ],
    );
  }

  /**
   * Checks entity relationship richness for a given node or the site at-large.
   *
   * For a node: inspects taxonomy term references, authorship, and entity
   * reference fields to evaluate how well the content is contextualised.
   *
   * For a site-level check (no node): verifies that the Taxonomy module is
   * enabled and that vocabularies exist.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   Optional node to inspect. Falls back to a site-level check when NULL.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   *   The audit check result.
   */
  public function checkEntityRelationships(?NodeInterface $node = NULL): TechnicalAuditResult {
    if ($node === NULL) {
      return $this->checkEntityRelationshipsSiteLevel();
    }

    return $this->checkEntityRelationshipsForNode($node);
  }

  // ---------------------------------------------------------------------------
  // Sprint 2 checks — feeds, language, JSON:API, licensing, date meta tags
  // ---------------------------------------------------------------------------

  /**
   * Checks for RSS/Atom/JSON feed availability for LLM content discovery.
   *
   * Probes common feed paths via HEAD requests and inspects the homepage HTML
   * for <link rel="alternate"> feed declarations.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   *   The audit check result.
   */
  public function checkFeedAvailability(): TechnicalAuditResult {
    $probePaths = ['/rss.xml', '/feed', '/node/feed', '/feed.json'];
    $feedsFound = [];
    $probeError = FALSE;

    foreach ($probePaths as $path) {
      $url = $this->getBaseUrl() . $path;
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

    $homepageUrl = $this->getBaseUrl() . '/';
    $html = $this->fetchPageHtml($homepageUrl);
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

    if ($probeError && $feedCount === 0) {
      $status = 'warning';
      $message = 'Feed availability check encountered network errors and no feeds could be confirmed.';
    }
    elseif ($feedCount >= 1) {
      $status = 'pass';
      $message = 'Found ' . $feedCount . ' feed(s): ' . implode(', ', $feedsFound) . ($htmlLinkTagsFound > 0 ? ' plus ' . $htmlLinkTagsFound . ' HTML link tag(s).' : '.');
    }
    else {
      $status = 'info';
      $message = 'No RSS, Atom, or JSON feeds detected. Adding feeds improves LLM incremental content discovery.';
    }

    return new TechnicalAuditResult(
      check: 'feed_availability',
      label: 'Feed Availability',
      status: $status,
      currentContent: NULL,
      recommendedContent: NULL,
      description: $message,
      details: [
        'feeds_found' => $feedsFound,
        'feed_count' => $feedCount,
        'has_rss' => $hasRss || in_array('/rss.xml', $feedsFound, TRUE) || in_array('/node/feed', $feedsFound, TRUE),
        'has_atom' => $hasAtom,
        'has_json_feed' => $hasJsonFeed || in_array('/feed.json', $feedsFound, TRUE),
        'html_link_tags_found' => $htmlLinkTagsFound,
      ],
    );
  }

  /**
   * Checks for HTML lang attribute and hreflang tags for language declaration.
   *
   * Fetches the homepage and inspects the <html> element's lang attribute
   * plus any <link rel="alternate" hreflang="..."> tags.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   *   The audit check result.
   */
  public function checkLanguageDeclaration(): TechnicalAuditResult {
    $homepageUrl = $this->getBaseUrl() . '/';
    $html = $this->fetchPageHtml($homepageUrl);

    if ($html === NULL) {
      return new TechnicalAuditResult(
        check: 'language_declaration',
        label: 'Language Declaration',
        status: 'warning',
        currentContent: NULL,
        recommendedContent: NULL,
        description: 'Could not fetch homepage HTML to check language declaration.',
        details: [
          'has_lang_attribute' => FALSE,
          'lang_value' => NULL,
          'hreflang_count' => 0,
          'hreflang_values' => [],
        ],
      );
    }

    // Parse <html lang="..."> attribute.
    $hasLang = FALSE;
    $langValue = NULL;
    if (preg_match('/<html[^>]*\slang=["\']([^"\']+)["\']/i', $html, $langMatch)) {
      $hasLang = TRUE;
      $langValue = $langMatch[1];
    }

    // Collect all hreflang values.
    $hreflangValues = [];
    preg_match_all(
      '/<link[^>]*rel=["\']alternate["\'][^>]*hreflang=["\']([^"\']+)["\']/i',
      $html,
      $hreflangMatches
    );
    if (!empty($hreflangMatches[1])) {
      $hreflangValues = $hreflangMatches[1];
    }
    // Also match the reverse attribute order: hreflang before rel.
    preg_match_all(
      '/<link[^>]*hreflang=["\']([^"\']+)["\'][^>]*rel=["\']alternate["\']/i',
      $html,
      $hreflangMatchesAlt
    );
    if (!empty($hreflangMatchesAlt[1])) {
      $hreflangValues = array_unique(array_merge($hreflangValues, $hreflangMatchesAlt[1]));
    }
    $hreflangValues = array_values($hreflangValues);

    // Determine status.
    $emptyLikeValues = ['', 'und', 'zxx'];
    if (!$hasLang) {
      $status = 'fail';
      $message = 'No lang attribute found on <html> element. Language declaration helps LLMs classify content correctly.';
    }
    elseif (in_array(strtolower((string) $langValue), $emptyLikeValues, TRUE)) {
      $status = 'warning';
      $message = 'lang attribute is present but has a non-specific value "' . $langValue . '". Set a specific language code (e.g. "en", "fr").';
    }
    else {
      $status = 'pass';
      $message = 'Language declared as "' . $langValue . '" on <html> element.'
        . (count($hreflangValues) > 0 ? ' ' . count($hreflangValues) . ' hreflang tag(s) found.' : '');
    }

    return new TechnicalAuditResult(
      check: 'language_declaration',
      label: 'Language Declaration',
      status: $status,
      currentContent: $langValue,
      recommendedContent: NULL,
      description: $message,
      details: [
        'has_lang_attribute' => $hasLang,
        'lang_value' => $langValue,
        'hreflang_count' => count($hreflangValues),
        'hreflang_values' => $hreflangValues,
      ],
    );
  }

  /**
   * Checks whether Drupal's JSON:API module is enabled and accessible.
   *
   * JSON:API enables machine-readable content endpoints suitable for
   * LLM RAG (retrieval-augmented generation) pipelines.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   *   The audit check result.
   */
  public function checkJsonApi(): TechnicalAuditResult {
    $moduleInstalled = $this->moduleHandler->moduleExists('jsonapi');
    $endpointAccessible = FALSE;

    if ($moduleInstalled) {
      $url = $this->getBaseUrl() . '/jsonapi';
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

    if ($moduleInstalled && $endpointAccessible) {
      $status = 'pass';
      $message = 'JSON:API module is enabled and the /jsonapi endpoint is accessible. This provides machine-readable content access for LLM RAG systems.';
    }
    elseif ($moduleInstalled) {
      $status = 'pass';
      $message = 'JSON:API module is enabled. The /jsonapi endpoint could not be confirmed accessible, but the module is installed.';
    }
    else {
      $status = 'info';
      $message = 'JSON:API module is not installed. Enabling it provides machine-readable RESTful endpoints useful for LLM RAG pipelines.';
    }

    return new TechnicalAuditResult(
      check: 'json_api',
      label: 'JSON:API',
      status: $status,
      currentContent: NULL,
      recommendedContent: NULL,
      description: $message,
      details: [
        'module_installed' => $moduleInstalled,
        'endpoint_accessible' => $endpointAccessible,
      ],
    );
  }

  /**
   * Checks for content licensing signals for AI usage rights communication.
   *
   * Inspects the homepage HTML for <link rel="license">, JSON-LD license
   * properties, and emerging AI-specific robots meta directives.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   *   The audit check result.
   */
  public function checkContentLicensing(): TechnicalAuditResult {
    $homepageUrl = $this->getBaseUrl() . '/';
    $html = $this->fetchPageHtml($homepageUrl);

    if ($html === NULL) {
      return new TechnicalAuditResult(
        check: 'content_licensing',
        label: 'Content Licensing',
        status: 'info',
        currentContent: NULL,
        recommendedContent: NULL,
        description: 'Could not fetch homepage HTML to check licensing signals.',
        details: [
          'has_license_link' => FALSE,
          'license_url' => NULL,
          'has_schema_license' => FALSE,
          'has_noai_directive' => FALSE,
          'has_noimageai_directive' => FALSE,
        ],
      );
    }

    // Check for <link rel="license" href="...">.
    $hasLicenseLink = FALSE;
    $licenseUrl = NULL;
    if (preg_match(
      '/<link[^>]+rel=["\']license["\'][^>]+href=["\']([^"\']+)["\']/i',
      $html,
      $licenseMatch
    ) || preg_match(
      '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']license["\']/i',
      $html,
      $licenseMatch
    )) {
      $hasLicenseLink = TRUE;
      $licenseUrl = $licenseMatch[1];
    }

    // Check JSON-LD blocks for a "license" property.
    $hasSchemaLicense = FALSE;
    preg_match_all(
      '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
      $html,
      $ldMatches
    );
    foreach ($ldMatches[1] ?? [] as $jsonText) {
      $data = json_decode(trim($jsonText), TRUE);
      if (!is_array($data)) {
        continue;
      }
      // Check flat objects and @graph arrays.
      $items = [];
      if (isset($data['@graph']) && is_array($data['@graph'])) {
        $items = $data['@graph'];
      }
      else {
        $items = [$data];
      }
      foreach ($items as $item) {
        if (is_array($item) && array_key_exists('license', $item)) {
          $hasSchemaLicense = TRUE;
          break 2;
        }
      }
    }

    // Check for noai / noimageai robots meta directives (emerging standard).
    $hasNoaiDirective = (bool) preg_match(
      '/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noai[^"\']*["\']/i',
      $html
    );
    $hasNoimageaiDirective = (bool) preg_match(
      '/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noimageai[^"\']*["\']/i',
      $html
    );

    $hasAnySignal = $hasLicenseLink || $hasSchemaLicense || $hasNoaiDirective || $hasNoimageaiDirective;

    if ($hasAnySignal) {
      $signals = [];
      if ($hasLicenseLink) {
        $signals[] = 'license link tag';
      }
      if ($hasSchemaLicense) {
        $signals[] = 'JSON-LD license property';
      }
      if ($hasNoaiDirective) {
        $signals[] = 'noai robots directive';
      }
      if ($hasNoimageaiDirective) {
        $signals[] = 'noimageai robots directive';
      }
      $status = 'pass';
      $message = 'Content licensing signals found: ' . implode(', ', $signals) . '.';
    }
    else {
      $status = 'info';
      $message = 'No content licensing signals detected. Adding a <link rel="license"> tag or JSON-LD license property helps AI systems understand content usage rights.';
    }

    return new TechnicalAuditResult(
      check: 'content_licensing',
      label: 'Content Licensing',
      status: $status,
      currentContent: $licenseUrl,
      recommendedContent: NULL,
      description: $message,
      details: [
        'has_license_link' => $hasLicenseLink,
        'license_url' => $licenseUrl,
        'has_schema_license' => $hasSchemaLicense,
        'has_noai_directive' => $hasNoaiDirective,
        'has_noimageai_directive' => $hasNoimageaiDirective,
      ],
    );
  }

  /**
   * Checks for Open Graph article date meta tags on the page.
   *
   * Inspects article:published_time and article:modified_time Open Graph
   * meta tags, which are distinct from Schema.org date properties and
   * provide temporal context for LLM systems.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The node to check. Falls back to the homepage when NULL.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   *   The audit check result.
   */
  public function checkDateMetaTags(?NodeInterface $node = NULL): TechnicalAuditResult {
    // Resolve URL (node canonical or homepage).
    $url = NULL;
    try {
      if ($node !== NULL) {
        $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()])
          ->setAbsolute()
          ->toString();
      }
      else {
        $url = $this->getBaseUrl() . '/';
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Technical audit: date meta tags URL resolution failed: @msg', ['@msg' => $e->getMessage()]);
    }

    $html = ($url !== NULL) ? $this->fetchPageHtml($url) : NULL;

    if ($html === NULL) {
      return new TechnicalAuditResult(
        check: 'date_meta_tags',
        label: 'Open Graph Date Meta Tags',
        status: 'info',
        currentContent: NULL,
        recommendedContent: NULL,
        description: 'Could not fetch page HTML to check Open Graph date meta tags.',
        details: [
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

    // Determine status.
    if ($hasPublishedTime && $publishedTimeValid && $hasModifiedTime && $modifiedTimeValid) {
      $status = 'pass';
      $message = 'Both article:published_time and article:modified_time are present with valid ISO 8601 values.';
    }
    elseif ($hasPublishedTime && $publishedTimeValid) {
      $status = 'warning';
      $message = 'article:published_time is present but article:modified_time is missing. Adding a modified time helps LLMs assess content freshness.';
    }
    elseif ($hasPublishedTime) {
      $status = 'warning';
      $message = 'article:published_time is present but the value "' . $publishedTimeValue . '" does not match ISO 8601 format.';
    }
    else {
      $status = 'info';
      $message = 'No Open Graph article date meta tags found. These provide temporal context for LLMs and social media scrapers.';
    }

    return new TechnicalAuditResult(
      check: 'date_meta_tags',
      label: 'Open Graph Date Meta Tags',
      status: $status,
      currentContent: $publishedTimeValue,
      recommendedContent: NULL,
      description: $message,
      details: [
        'has_published_time' => $hasPublishedTime,
        'published_time_value' => $publishedTimeValue,
        'published_time_valid' => $publishedTimeValid,
        'has_modified_time' => $hasModifiedTime,
        'modified_time_value' => $modifiedTimeValue,
        'modified_time_valid' => $modifiedTimeValid,
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Protected helpers — HTTP fetching
  // ---------------------------------------------------------------------------

  /**
   * Fetches page HTML with in-memory caching to avoid redundant HTTP requests.
   *
   * Multiple checks within the same audit run (canonical URL, schema markup)
   * inspect the same page URL. This method ensures the page is fetched at most
   * once per URL per service instance lifetime.
   *
   * @param string $url
   *   The absolute URL to fetch.
   *
   * @return string|null
   *   The HTML body string, or NULL if the request failed or returned an error.
   */
  protected function fetchPageHtml(string $url): ?string {
    if (array_key_exists($url, $this->htmlCache)) {
      return $this->htmlCache[$url];
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
        'headers' => ['User-Agent' => 'DrupalAiContentAudit/1.0'],
        'http_errors' => FALSE,
      ]);
      $html = (string) $response->getBody();
      $this->htmlCache[$url] = $html;
      return $html;
    }
    catch (\Exception $e) {
      $this->htmlCache[$url] = NULL;
      return NULL;
    }
  }

  // ---------------------------------------------------------------------------
  // Protected helpers — schema markup parsing
  // ---------------------------------------------------------------------------

  /**
   * Extracts all @type values from JSON-LD script blocks in HTML.
   *
   * Handles both flat objects ({ "@type": "Article" }) and @graph arrays
   * ({ "@graph": [{ "@type": "WebPage" }, ...] }).
   *
   * @param string $html
   *   The full HTML source to scan.
   *
   * @return string[]
   *   Deduplicated list of @type values found across all JSON-LD blocks.
   */
  protected function extractSchemaTypes(string $html): array {
    preg_match_all(
      '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
      $html,
      $matches
    );

    $types = [];
    foreach ($matches[1] ?? [] as $jsonText) {
      $data = json_decode(trim($jsonText), TRUE);
      if (!is_array($data)) {
        continue;
      }
      // Collect types from a @graph array.
      if (isset($data['@graph']) && is_array($data['@graph'])) {
        foreach ($data['@graph'] as $item) {
          $this->collectSchemaType($item, $types);
        }
      }
      else {
        $this->collectSchemaType($data, $types);
      }
    }

    return array_values(array_unique($types));
  }

  /**
   * Extracts article date properties from JSON-LD structured data in HTML.
   *
   * Inspects any JSON-LD objects whose @type is Article, NewsArticle, or
   * BlogPosting and checks for the presence and ISO 8601 format validity of
   * datePublished and dateModified properties.
   *
   * This method does NOT affect the pass/fail status of the schema check; its
   * results are provided as informational details for downstream consumers.
   *
   * @param string $html
   *   The full HTML source to scan.
   *
   * @return array{
   *   article_has_date_published: bool,
   *   article_has_date_modified: bool,
   *   date_published_valid_format: bool,
   *   date_modified_valid_format: bool,
   *   }
   *   Date property findings for the first article-type object found.
   */
  protected function extractSchemaDateProperties(string $html): array {
    $result = [
      'article_has_date_published' => FALSE,
      'article_has_date_modified' => FALSE,
      'date_published_valid_format' => FALSE,
      'date_modified_valid_format' => FALSE,
    ];

    $articleTypes = ['Article', 'NewsArticle', 'BlogPosting'];
    $iso8601Regex = '/^\d{4}-\d{2}-\d{2}/';

    preg_match_all(
      '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
      $html,
      $matches
    );

    foreach ($matches[1] ?? [] as $jsonText) {
      $data = json_decode(trim($jsonText), TRUE);
      if (!is_array($data)) {
        continue;
      }

      // Collect candidate items from either flat or @graph format.
      $items = [];
      if (isset($data['@graph']) && is_array($data['@graph'])) {
        $items = $data['@graph'];
      }
      else {
        $items = [$data];
      }

      foreach ($items as $item) {
        if (!is_array($item) || !isset($item['@type'])) {
          continue;
        }

        // Normalise @type to an array for uniform handling.
        $types = is_array($item['@type']) ? $item['@type'] : [$item['@type']];
        $isArticle = !empty(array_intersect($types, $articleTypes));

        if (!$isArticle) {
          continue;
        }

        // Found an article-type object — check date properties.
        if (isset($item['datePublished'])) {
          $result['article_has_date_published'] = TRUE;
          $result['date_published_valid_format'] = (bool) preg_match($iso8601Regex, (string) $item['datePublished']);
        }
        if (isset($item['dateModified'])) {
          $result['article_has_date_modified'] = TRUE;
          $result['date_modified_valid_format'] = (bool) preg_match($iso8601Regex, (string) $item['dateModified']);
        }

        // Stop after the first article-type item.
        return $result;
      }
    }

    return $result;
  }

  /**
   * Collects @type value(s) from a single decoded JSON-LD item.
   *
   * @param array $item
   *   Decoded JSON-LD item.
   * @param string[] $types
   *   Running list of types; updated in-place.
   */
  protected function collectSchemaType(array $item, array &$types): void {
    if (!isset($item['@type'])) {
      return;
    }
    // @type can be a string or an array of strings.
    $typeValue = $item['@type'];
    if (is_string($typeValue)) {
      $types[] = $typeValue;
    }
    elseif (is_array($typeValue)) {
      foreach ($typeValue as $t) {
        if (is_string($t)) {
          $types[] = $t;
        }
      }
    }
  }

  /**
   * Counts the number of application/ld+json script elements in HTML.
   *
   * @param string $html
   *   The HTML source.
   *
   * @return int
   *   The number of JSON-LD script elements found.
   */
  protected function countJsonLdScripts(string $html): int {
    preg_match_all(
      '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>/si',
      $html,
      $matches
    );
    return count($matches[0] ?? []);
  }

  /**
   * Generates a recommended Schema.org implementation summary.
   *
   * @return string
   *   Recommended Schema.org implementation guidance.
   */
  protected function generateSchemaRecommendation(): string {
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

  // ---------------------------------------------------------------------------
  // Protected helpers — entity relationship checks
  // ---------------------------------------------------------------------------

  /**
   * Performs entity relationship checks for a specific node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to inspect.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   *   The audit check result.
   */
  protected function checkEntityRelationshipsForNode(NodeInterface $node): TechnicalAuditResult {
    $owner = $node->getOwner();
    $authorName = $owner ? $owner->getDisplayName() : 'Unknown';
    $hasRealAuthor = $owner && ($owner->id() > 0) && ($owner->getDisplayName() !== 'Anonymous');

    $taxonomyTermsByField = [];
    $entityRefFieldCount = 0;
    $totalRefsCount = 0;

    foreach ($node->getFieldDefinitions() as $fieldName => $fieldDefinition) {
      if ($fieldDefinition->getType() !== 'entity_reference') {
        continue;
      }

      $field = $node->get($fieldName);
      if ($field->isEmpty()) {
        continue;
      }

      $targetType = $fieldDefinition->getSetting('target_type');
      $referencedEntities = $field->referencedEntities();

      if (empty($referencedEntities)) {
        continue;
      }

      $entityRefFieldCount++;
      $totalRefsCount += count($referencedEntities);

      if ($targetType === 'taxonomy_term') {
        $termNames = [];
        foreach ($referencedEntities as $term) {
          $termNames[] = $term->label();
        }
        if (!empty($termNames)) {
          $taxonomyTermsByField[$fieldName] = $termNames;
        }
      }
    }

    $hasTaxonomyTerms = !empty($taxonomyTermsByField);
    $taxonomyTermsCount = array_sum(array_map('count', $taxonomyTermsByField));

    // Determine status.
    if ($hasRealAuthor && $hasTaxonomyTerms && $entityRefFieldCount >= 1) {
      $status = 'pass';
      $description = 'Rich entity relationships: author set, '
        . $taxonomyTermsCount . ' taxonomy term(s) across '
        . count($taxonomyTermsByField) . ' field(s), and '
        . $entityRefFieldCount . ' entity reference field(s).';
    }
    elseif ($hasRealAuthor || $hasTaxonomyTerms) {
      $status = 'warning';
      $parts = [];
      if ($hasRealAuthor) {
        $parts[] = 'author "' . $authorName . '" set';
      }
      if ($hasTaxonomyTerms) {
        $parts[] = $taxonomyTermsCount . ' taxonomy term(s)';
      }
      $missing = [];
      if (!$hasRealAuthor) {
        $missing[] = 'real author';
      }
      if (!$hasTaxonomyTerms) {
        $missing[] = 'taxonomy terms';
      }
      $description = 'Partial entity relationships: ' . implode(' and ', $parts) . '. '
        . 'Missing: ' . implode(', ', $missing) . '.';
    }
    else {
      $status = 'fail';
      $description = 'No meaningful entity relationships found. Assign a named author, '
        . 'add taxonomy terms, and link to related content to improve LLM context.';
    }

    return new TechnicalAuditResult(
      check: 'entity_relationships',
      label: 'Entity Relationships',
      status: $status,
      currentContent: NULL,
      recommendedContent: NULL,
      description: $description,
      details: [
        'author_name' => $authorName,
        'has_real_author' => $hasRealAuthor,
        'taxonomy_terms' => $taxonomyTermsByField,
        'taxonomy_fields_count' => count($taxonomyTermsByField),
        'taxonomy_terms_used' => $taxonomyTermsCount,
        'entity_ref_count' => $entityRefFieldCount,
        'total_references_count' => $totalRefsCount,
      ],
    );
  }

  /**
   * Performs a site-level entity relationship readiness check (no node).
   *
   * Checks whether the Taxonomy module is enabled and vocabularies are
   * configured, giving a baseline signal for relationship infrastructure.
   *
   * @return \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult
   *   The audit check result.
   */
  protected function checkEntityRelationshipsSiteLevel(): TechnicalAuditResult {
    $taxonomyEnabled = $this->moduleHandler->moduleExists('taxonomy');

    $vocabularyCount = 0;
    if ($taxonomyEnabled) {
      try {
        $vocabularyCount = (int) $this->entityTypeManager
          ->getStorage('taxonomy_vocabulary')
          ->getQuery()
          ->accessCheck(FALSE)
          ->count()
          ->execute();
      }
      catch (\Exception $e) {
        $this->logger->warning('Technical audit: could not count taxonomy vocabularies: @msg', ['@msg' => $e->getMessage()]);
      }
    }

    if (!$taxonomyEnabled) {
      $status = 'fail';
      $description = 'Taxonomy module is not installed. Entity relationships and topic classification are essential for LLM content context.';
    }
    elseif ($vocabularyCount === 0) {
      $status = 'warning';
      $description = 'Taxonomy module is installed but no vocabularies are configured. Create vocabularies and tag content to improve entity relationship richness.';
    }
    else {
      $status = 'pass';
      $description = 'Taxonomy module is installed with ' . $vocabularyCount . ' vocabulary/vocabularies configured. Ensure content types use entity reference fields to tag with terms.';
    }

    return new TechnicalAuditResult(
      check: 'entity_relationships',
      label: 'Entity Relationships',
      status: $status,
      currentContent: NULL,
      recommendedContent: NULL,
      description: $description,
      details: [
        'taxonomy_enabled' => $taxonomyEnabled,
        'vocabulary_count' => $vocabularyCount,
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Protected helpers — URL, content generation, caching
  // ---------------------------------------------------------------------------

  /**
   * Gets the site base URL.
   */
  protected function getBaseUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      return $request->getSchemeAndHttpHost();
    }
    // Fallback.
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
   * @return array<string, \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult>|null
   *   Cached check results keyed by check ID, or NULL if none.
   */
  protected function getCachedResults(): ?array {
    $cached = $this->cacheData->get('ai_content_audit:technical_audit');
    if (!$cached) {
      return NULL;
    }

    // Reconstruct value objects from cached arrays, preserving string keys.
    $results = [];
    foreach ($cached->data as $key => $data) {
      $results[$key] = new TechnicalAuditResult(
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
   * @param array<string, \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult> $results
   *   Check results to store in the cache backend.
   */
  protected function cacheResults(array $results): void {
    $serialized = array_map(fn(TechnicalAuditResult $r) => $r->toArray(), $results);
    $this->cacheData->set('ai_content_audit:technical_audit', $serialized, time() + static::CACHE_TTL);
  }

}
