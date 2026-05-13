<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\ContentExtractor;

use Drupal\ai_content_audit\Extractor\ContentExtractorInterface;
use Drupal\ai_content_audit\Extractor\EntityContextTrait;
use Drupal\ai_content_audit\Service\HtmlFetchService;
use Drupal\ai_content_audit\Service\LayoutBuilderPreviewSource;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Extracts content from a node by rendering its HTML and converting to structured text.
 *
 * Renders the node through Drupal's entity view builder, then parses the
 * resulting HTML with DOMDocument to produce a semantically annotated
 * plain-text string that preserves structural signals for the LLM:
 *
 * - Heading elements (h1–h6) are converted to markdown-style markers such as
 *   "# H1: Title" so the LLM can interpret content hierarchy.
 * - Image elements become "[Image: alt text]" or "[Image: no alt text]" so
 *   image accessibility is visible without binary data.
 * - Anchor elements become "[Link: anchor (internal|external: href)]" so link
 *   presence and destinations are readable in plain text.
 * - Table elements are serialised to a compact grid format bounded by [Table]
 *   and [/Table] markers.
 * - List items are converted to bullet or numbered markers.
 * - Navigation chrome, scripts, styles, and visually-hidden elements are
 *   stripped before content extraction.
 *
 * The extracted string is assembled in three sections:
 * 1. A "--- Content Metadata ---" header block prepended via
 *    EntityContextTrait::buildContentMetadataBlock().
 * 2. The HTML body converted to structured plain text.
 * 3. An "--- Entity Context ---" footer block appended via
 *    EntityContextTrait::buildEntityContextBlock().
 *
 * @ContentExtractor(
 *   id = "html_rendered",
 *   label = @Translation("HTML Rendered Extractor"),
 *   description = @Translation("Renders node HTML and converts to structured text preserving headings, images, and links."),
 *   render_mode = "html"
 * )
 */
class HtmlExtractor extends PluginBase implements ContentExtractorInterface, ContainerFactoryPluginInterface {

  use EntityContextTrait;

  /**
   * Constructs an HtmlExtractor plugin instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service, used to obtain the node view builder.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service, used to render the node's build array to HTML.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory, used to read max_chars_per_request.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, used to resolve the site host for link classification.
   * @param \Drupal\ai_content_audit\Service\LayoutBuilderPreviewSource $layoutBuilderPreviewSource
   *   Builds Layout Builder section render arrays when LB is enabled for the node.
   * @param \Drupal\ai_content_audit\Service\HtmlFetchService $htmlFetchService
   *   Fetches live page HTML via HTTP, used as the primary extraction strategy.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
    protected ConfigFactoryInterface $configFactory,
    protected RequestStack $requestStack,
    protected LayoutBuilderPreviewSource $layoutBuilderPreviewSource,
    protected HtmlFetchService $htmlFetchService,
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
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('config.factory'),
      $container->get('request_stack'),
      $container->get('ai_content_audit.layout_builder_preview_source'),
      $container->get('ai_content_audit.html_fetch'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $mode): bool {
    return ($this->pluginDefinition['render_mode'] ?? '') === $mode;
  }

  /**
   * {@inheritdoc}
   */
  public function getMode(): string {
    return $this->pluginDefinition['render_mode'] ?? '';
  }

  /**
   * {@inheritdoc}
   *
   * Extracts content using a three-strategy cascade, trying each in order and
   * falling back to the next when the previous yields insufficient content:
   *
   * 1. HTTP fetch with session-cookie forwarding (primary): Requests the page's
   *    canonical URL using the current user's authenticated session. This
   *    produces the exact HTML the browser renders, including all Layout Builder
   *    blocks, theme templates, metatags, heading hierarchy, and image alt text.
   *    Works for any Drupal content type without site-specific assumptions.
   *
   * 2. PHP entity rendering (secondary): Renders the node via Drupal's entity
   *    view builder and Layout Builder section rendering pipeline. Reliable for
   *    simple content types and post-type nodes. May produce empty body content
   *    for complex Layout Builder nodes in CLI/Drush contexts because inline
   *    block plugins skip unpublished block_content entities that fail access
   *    checks without a live HTTP session.
   *
   * 3. Inline block text extraction (tertiary): Directly loads block_content
   *    entity revisions referenced by the node's Layout Builder override field
   *    and extracts their text fields without rendering. Produces plain text
   *    without heading markers but ensures the LLM receives actual content
   *    rather than only metadata when strategies 1 and 2 both fail.
   */
  public function extract(NodeInterface $node): string {
    $maxChars = $this->getMaxChars();

    // Build surrounding context blocks from the trait (always present).
    $metadata = $this->buildContentMetadataBlock($node);
    $entityContext = $this->buildEntityContextBlock($node);

    $bodyText = $this->extractBodyText($node);

    $parts = array_filter(
      [$metadata, $bodyText, $entityContext],
      fn(string $p): bool => $p !== ''
    );
    $content = implode("\n\n", $parts);

    if (mb_strlen($content) > $maxChars) {
      $content = mb_substr($content, 0, $maxChars) . "\n[Content truncated for assessment]";
    }

    return $content;
  }

  /**
   * Runs the three-strategy cascade and returns the best body text available.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to extract content from.
   *
   * @return string
   *   Structured plain text body (may be empty if all strategies fail).
   */
  private function extractBodyText(NodeInterface $node): string {
    // --- Strategy 1: HTTP fetch with session-cookie forwarding ---
    $html = $this->fetchNodeHtmlViaHttp($node);
    if (!empty(trim($html))) {
      $bodyText = $this->convertHtmlToStructuredText($html);
      if ($bodyText === '') {
        $bodyText = $this->convertHtmlToPlainTextFallback($html);
      }
      if (mb_strlen($bodyText) >= 80) {
        return $bodyText;
      }
    }

    // --- Strategy 2: PHP entity rendering (LB sections + view builder) ---
    $html = $this->renderNodeToHtml($node);
    if (!empty(trim($html))) {
      $bodyText = $this->convertHtmlToStructuredText($html);
      if ($bodyText === '') {
        $bodyText = $this->convertHtmlToPlainTextFallback($html);
      }
      if (mb_strlen($bodyText) >= 80) {
        return $bodyText;
      }
    }

    // --- Strategy 3: Direct inline block field text extraction ---
    $inlineText = $this->layoutBuilderPreviewSource->extractTextFromInlineBlocks($node, 'full');
    if ($inlineText !== '') {
      return $inlineText;
    }

    return '';
  }

  /**
   * Fetches the page HTML by making an authenticated HTTP request.
   *
   * Forwards the current session cookies so the request is handled as the
   * authenticated editor, giving access to both published and draft content.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose page should be fetched.
   *
   * @return string
   *   The raw HTML response body, or an empty string on failure.
   */
  private function fetchNodeHtmlViaHttp(NodeInterface $node): string {
    try {
      $url = $node->toUrl('canonical')->setAbsolute(TRUE)->toString();
    }
    catch (\Throwable) {
      return '';
    }

    // Build a Cookie header from the current request's cookies so the HTTP
    // client is treated as an authenticated user — required for draft nodes
    // and any access-controlled content.
    $cookieHeader = '';
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL) {
      $pairs = [];
      foreach ($request->cookies->all() as $name => $value) {
        $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
      }
      $cookieHeader = implode('; ', $pairs);
    }

    return $this->htmlFetchService->fetchPageHtml($url, $cookieHeader) ?? '';
  }

  /**
   * Renders the node to HTML via the PHP entity view + Layout Builder pipeline.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to render.
   *
   * @return string
   *   The rendered HTML, or an empty string when rendering fails.
   */
  private function renderNodeToHtml(NodeInterface $node): string {
    $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
    $layoutBuild = $this->layoutBuilderPreviewSource->buildSectionsRenderArray($node, 'full');
    $renderArray = $layoutBuild !== []
      ? [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-content-audit__layout-builder-extract']],
        'sections' => $layoutBuild,
      ]
      : $viewBuilder->view($node, 'full');

    try {
      return (string) $this->renderer->executeInRenderContext(
        new RenderContext(),
        function () use ($renderArray): string {
          return (string) $this->renderer->render($renderArray);
        }
      );
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Converts a rendered HTML string to structured plain text.
   *
   * Uses DOMDocument and DOMXPath to walk the DOM tree, replacing structural
   * elements with plain-text markers in-place before extracting the final
   * text content of the cleaned document.
   *
   * Processing order:
   * 1. Strip navigation chrome, scripts, styles, and visually-hidden elements.
   * 2. Convert tables (innermost first to handle nested tables correctly).
   * 3. Convert unordered and ordered lists to bullet/numbered markers.
   * 4. Convert images to alt-text markers.
   * 5. Convert links to classified link markers.
   * 6. Convert headings to markdown-style markers.
   *
   * @param string $html
   *   The rendered HTML string to convert.
   *
   * @return string
   *   Structured plain text with whitespace normalised, or an empty string
   *   when the HTML contains no extractable content.
   */
  protected function convertHtmlToStructuredText(string $html): string {
    if (empty(trim($html))) {
      return '';
    }

    $doc = new \DOMDocument();
    // Suppress malformed-markup warnings; libxml errors are cleared below.
    libxml_use_internal_errors(TRUE);
    // The <?xml encoding="UTF-8"> preamble ensures multi-byte characters are
    // handled correctly by libxml without a meta charset declaration.
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new \DOMXPath($doc);

    // Extract all <meta> tags from <head> before stripping the DOM.
    // Meta elements have no text content — their values live in the "content"
    // attribute — so textContent extraction below would miss them entirely.
    // This captures name/property/http-equiv metas: description, robots,
    // keywords, og:*, twitter:*, canonical hints, etc.
    $metaLines = $this->extractMetaTags($xpath);

    // Strip navigation chrome, scripts, styles, and hidden elements first so
    // they do not contribute text to the output.
    $this->stripUnwantedElements($xpath);

    // Convert structural elements to text node markers. Tables are processed
    // before lists/images/links so that nested elements within cells are
    // captured via textContent before the surrounding structure is removed.
    // Tables are reversed so innermost tables are processed first, preventing
    // outer table row queries from matching already-replaced inner tables.
    $this->convertTables($xpath);
    $this->convertLists($xpath);
    $this->convertImages($xpath);
    $this->convertLinks($xpath, $this->getSiteHost());
    $this->convertHeadings($xpath);

    // Extract the full text content of the cleaned document.
    $text = $doc->textContent;

    // Decode any remaining HTML entities present in text nodes.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Collapse inline runs of horizontal whitespace (spaces/tabs) while
    // preserving the structural newlines added by the converters above.
    $text = (string) preg_replace('/[^\S\n]+/', ' ', $text);

    // Normalize lines that consist of only whitespace to empty lines.
    // This handles the common case where layout wrappers and theme containers
    // render as " \n \n \n" (spaces between newlines) rather than "\n\n\n",
    // which would otherwise defeat the consecutive-newline collapse below.
    $text = (string) preg_replace('/^ +$/m', '', $text);

    // Collapse runs of three or more consecutive newlines to at most two.
    $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

    $text = trim($text);

    // Prepend all extracted meta tags so the LLM can assess SEO completeness
    // (description, robots, keywords, og:*, twitter:*, etc.).
    if (!empty($metaLines)) {
      $metaBlock = "--- Page Meta Tags ---\n" . implode("\n", $metaLines);
      $text = $metaBlock . "\n\n" . $text;
    }

    return $text;
  }

  /**
   * Converts rendered HTML to plain text as a conservative fallback.
   *
   * This is used only when structured conversion unexpectedly yields an empty
   * string. It keeps the extractor resilient for themes/layouts that use
   * wrappers not handled by the structured converter.
   */
  protected function convertHtmlToPlainTextFallback(string $html): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string) preg_replace('/[^\S\n]+/', ' ', $text);
    $text = (string) preg_replace('/^ +$/m', '', $text);
    $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
  }

  /**
   * Extracts all <meta> tag key/value pairs from the document head.
   *
   * Iterates every <meta> element and resolves its label from (in priority
   * order): the "name" attribute, the "property" attribute (Open Graph /
   * Twitter Card), or the "http-equiv" attribute. The value is always taken
   * from the "content" attribute. Elements that have no content or no
   * identifiable label are skipped.
   *
   * Returns lines formatted as "Meta {Label}: {value}" so the LLM can assess
   * SEO completeness across description, robots, keywords, og:title, og:image,
   * twitter:card, and any other custom meta tags present on the page.
   *
   * @param \DOMXPath $xpath
   *   The XPath object bound to the document being processed.
   *
   * @return string[]
   *   Array of formatted "Meta {label}: {value}" strings, one per tag found.
   */
  protected function extractMetaTags(\DOMXPath $xpath): array {
    $lines = [];
    $seen = [];

    $nodes = iterator_to_array($xpath->query('//meta') ?: [], FALSE);
    foreach ($nodes as $node) {
      if (!$node instanceof \DOMElement) {
        continue;
      }

      // Resolve the label: name > property > http-equiv.
      $label = $node->getAttribute('name')
        ?: $node->getAttribute('property')
        ?: $node->getAttribute('http-equiv');
      $label = trim($label);

      $value = trim($node->getAttribute('content'));

      if ($label === '' || $value === '') {
        continue;
      }

      // Skip charset and viewport — purely technical, not editorial.
      if (in_array(strtolower($label), ['viewport', 'charset', 'generator'], TRUE)) {
        continue;
      }

      // Deduplicate by label (keep first occurrence).
      if (isset($seen[$label])) {
        continue;
      }
      $seen[$label] = TRUE;

      $lines[] = 'Meta ' . $label . ': ' . $value;
    }

    return $lines;
  }

  /**
   * Strips navigation chrome and invisible elements from the DOM.
   *
   * Removes <script>, <style>, <nav>, and any element whose class attribute
   * contains "visually-hidden" or "hidden" so that theme chrome and
   * screen-reader-only text do not pollute the LLM-facing content.
   *
   * Intentionally does NOT remove <header> or <footer>: entity and Layout
   * Builder output often places real page content inside those elements; stripping
   * them would leave empty extraction (only metadata) for many case studies.
   *
   * @param \DOMXPath $xpath
   *   The XPath object bound to the document being processed.
   */
  protected function stripUnwantedElements(\DOMXPath $xpath): void {
    $queries = [
      '//script',
      '//style',
      '//nav',
      '//*[contains(concat(" ",normalize-space(@class)," ")," visually-hidden ")]',
      '//*[contains(concat(" ",normalize-space(@class)," ")," hidden ")]',
    ];

    foreach ($queries as $query) {
      // Collect into an array first because DOMNodeList is live; removing
      // a node during forward iteration would skip its following sibling.
      $nodes = iterator_to_array($xpath->query($query) ?: [], FALSE);
      foreach ($nodes as $node) {
        if ($node->parentNode !== NULL) {
          $node->parentNode->removeChild($node);
        }
      }
    }
  }

  /**
   * Converts heading elements (h1–h6) to markdown-style text markers.
   *
   * Each heading element is replaced with a text node in the format:
   * @code
   * \n{#markers} H{level}: {heading text}\n
   * @endcode
   *
   * Example: <h2>Section Title</h2> → "\n## H2: Section Title\n"
   *
   * @param \DOMXPath $xpath
   *   The XPath object bound to the document being processed.
   */
  protected function convertHeadings(\DOMXPath $xpath): void {
    for ($level = 1; $level <= 6; $level++) {
      // Collect to array before modifying: DOMNodeList is live.
      $nodes = iterator_to_array($xpath->query("//h{$level}") ?: [], FALSE);
      foreach ($nodes as $node) {
        $text = trim($node->textContent);
        $marker = str_repeat('#', $level);
        $textNode = $node->ownerDocument->createTextNode(
          "\n{$marker} H{$level}: {$text}\n"
        );
        $node->parentNode->replaceChild($textNode, $node);
      }
    }
  }

  /**
   * Converts image elements to plain-text alt-text markers.
   *
   * Each <img> element is replaced with a text node:
   * - " [Image: {alt text}] " when the alt attribute is non-empty.
   * - " [Image: no alt text] " when the alt attribute is absent or empty.
   *
   * @param \DOMXPath $xpath
   *   The XPath object bound to the document being processed.
   */
  protected function convertImages(\DOMXPath $xpath): void {
    $nodes = iterator_to_array($xpath->query('//img') ?: [], FALSE);
    foreach ($nodes as $node) {
      $alt = trim($node->getAttribute('alt'));
      $marker = $alt !== '' ? " [Image: {$alt}] " : ' [Image: no alt text] ';
      $textNode = $node->ownerDocument->createTextNode($marker);
      $node->parentNode->replaceChild($textNode, $node);
    }
  }

  /**
   * Converts anchor elements to classified plain-text link markers.
   *
   * Each <a href="…"> element is replaced with a text node:
   * - " [Link: {text} (internal: {path})] " for internal links.
   * - " [Link: {text} (external: {url})] " for external links.
   *
   * Classification rules (applied in order):
   * - href starts with "/" → internal (site-relative path).
   * - href starts with "#" → internal (fragment-only).
   * - href contains the site host name → internal.
   * - All other hrefs → external.
   *
   * @param \DOMXPath $xpath
   *   The XPath object bound to the document being processed.
   * @param string $siteHost
   *   The site's host name (e.g. "example.com") used for internal link
   *   classification. Pass an empty string to disable host matching.
   */
  protected function convertLinks(\DOMXPath $xpath, string $siteHost): void {
    $nodes = iterator_to_array($xpath->query('//a[@href]') ?: [], FALSE);
    foreach ($nodes as $node) {
      $href = trim($node->getAttribute('href'));
      $text = trim($node->textContent);
      if ($text === '') {
        $text = $href;
      }

      // Classify as internal when the href is path-relative, fragment-only,
      // or explicitly references the current site's host name.
      $isInternal = str_starts_with($href, '/')
        || str_starts_with($href, '#')
        || ($siteHost !== '' && str_contains($href, $siteHost));

      $type = $isInternal ? 'internal' : 'external';
      $marker = " [Link: {$text} ({$type}: {$href})] ";

      $textNode = $node->ownerDocument->createTextNode($marker);
      $node->parentNode->replaceChild($textNode, $node);
    }
  }

  /**
   * Converts table elements to compact text grid representations.
   *
   * Each <table> element is replaced with a text node in the format:
   * @code
   * [Table]
   * | Header1 | Header2 | Header3 |
   * | Cell1   | Cell2   | Cell3   |
   * [/Table]
   * @endcode
   *
   * Tables are processed in reverse document order so nested tables are
   * converted before their containing tables are processed, preventing
   * inner table rows from being double-counted in outer table cell text.
   *
   * @param \DOMXPath $xpath
   *   The XPath object bound to the document being processed.
   */
  protected function convertTables(\DOMXPath $xpath): void {
    // Reverse document order so innermost (deepest) tables are converted first.
    $tables = array_reverse(
      iterator_to_array($xpath->query('//table') ?: [], FALSE)
    );

    foreach ($tables as $table) {
      $rows = [];

      // Query all <tr> elements anywhere within this table element, including
      // those inside <thead>, <tbody>, and <tfoot> wrappers.
      $trNodes = iterator_to_array(
        $xpath->query('.//tr', $table) ?: [],
        FALSE
      );

      foreach ($trNodes as $tr) {
        $cells = [];
        // Collect direct <th> and <td> descendants of this row.
        $cellNodes = iterator_to_array(
          $xpath->query('.//th|.//td', $tr) ?: [],
          FALSE
        );
        foreach ($cellNodes as $cell) {
          $cells[] = trim($cell->textContent);
        }
        if (!empty($cells)) {
          $rows[] = '| ' . implode(' | ', $cells) . ' |';
        }
      }

      $tableText = "\n[Table]\n" . implode("\n", $rows) . "\n[/Table]\n";
      $textNode = $table->ownerDocument->createTextNode($tableText);
      $table->parentNode->replaceChild($textNode, $table);
    }
  }

  /**
   * Converts list elements (ul/ol) to plain-text bullet and numbered markers.
   *
   * Unordered list items (<li> in <ul>) are prefixed with "• ".
   * Ordered list items (<li> in <ol>) are prefixed with sequential numbers
   * ("1. ", "2. ", …).
   *
   * Ordered lists are processed before unordered lists so that mixed nesting
   * (ol inside ul or vice versa) is handled from innermost to outermost.
   *
   * @param \DOMXPath $xpath
   *   The XPath object bound to the document being processed.
   */
  protected function convertLists(\DOMXPath $xpath): void {
    // Process ordered lists first (collect before any DOM mutations).
    $olNodes = iterator_to_array($xpath->query('//ol') ?: [], FALSE);
    foreach ($olNodes as $ol) {
      $items = iterator_to_array($xpath->query('./li', $ol) ?: [], FALSE);
      $text = "\n";
      $counter = 1;
      foreach ($items as $li) {
        $text .= $counter . '. ' . trim($li->textContent) . "\n";
        $counter++;
      }
      $textNode = $ol->ownerDocument->createTextNode($text);
      $ol->parentNode->replaceChild($textNode, $ol);
    }

    // Process unordered lists.
    $ulNodes = iterator_to_array($xpath->query('//ul') ?: [], FALSE);
    foreach ($ulNodes as $ul) {
      $items = iterator_to_array($xpath->query('./li', $ul) ?: [], FALSE);
      $text = "\n";
      foreach ($items as $li) {
        $text .= '• ' . trim($li->textContent) . "\n";
      }
      $textNode = $ul->ownerDocument->createTextNode($text);
      $ul->parentNode->replaceChild($textNode, $ul);
    }
  }

  /**
   * Returns the maximum character count allowed per AI assessment request.
   *
   * Reads the max_chars_per_request key from ai_content_audit.settings
   * configuration. Falls back to 8000 if the setting is absent or zero.
   *
   * @return int
   *   The maximum character count for assembled content.
   */
  protected function getMaxChars(): int {
    $config = $this->configFactory->get('ai_content_audit.settings');
    $configured = (int) ($config->get('max_chars_per_request') ?? 0);
    return $configured > 0 ? $configured : 8000;
  }

  /**
   * Returns the current site's host name for internal link classification.
   *
   * Retrieves the host from the current request via the request stack.
   * Returns an empty string when no request is available (e.g. during
   * CLI/drush execution), which disables host-based link classification and
   * falls back to path-relative detection only.
   *
   * @return string
   *   The hostname (e.g. "example.com"), or an empty string if unavailable.
   */
  protected function getSiteHost(): string {
    $request = $this->requestStack->getCurrentRequest();
    return $request !== NULL ? $request->getHost() : '';
  }

}
