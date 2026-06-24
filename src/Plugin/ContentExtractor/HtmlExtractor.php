<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\ContentExtractor;

use Drupal\ai_content_audit\Extractor\ContentExtractorInterface;
use Drupal\ai_content_audit\Extractor\EntityContextTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\MainContent\HtmlRenderer;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\metatag\MetatagManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Extracts node content, rendering its HTML and converting to structured text.
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
 * - Table elements are serialized to a compact grid format bounded by [Table]
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
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager.
   * @param \Drupal\Core\Theme\ThemeInitializationInterface $themeInitialization
   *   The theme initialization.
   * @param \Drupal\Core\Render\MainContent\HtmlRenderer $mainContentHtmlRenderer
   *   The HTML renderer service.
   * @param \Symfony\Component\HttpFoundation\Request $currentRequest
   *   The Request object.
   * @param \Drupal\Core\Render\AttachmentsResponseProcessorInterface $attachmentsProcessor
   *   The HTML response attachments processor service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\metatag\MetatagManagerInterface|null $metatagManager
   *   The Metatag manager service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
    protected ConfigFactoryInterface $configFactory,
    protected ThemeManagerInterface $themeManager,
    protected ThemeInitializationInterface $themeInitialization,
    protected HtmlRenderer $mainContentHtmlRenderer,
    protected Request $currentRequest,
    protected AttachmentsResponseProcessorInterface $attachmentsProcessor,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ?MetatagManagerInterface $metatagManager = NULL,
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
      $container->get('theme.manager'),
      $container->get('theme.initialization'),
      $container->get('main_content_renderer.html'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('html_response.attachments_processor'),
      $container->get('module_handler'),
      $container->has('metatag.manager') ? $container->get('metatag.manager') : NULL,
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
   * Renders the node to HTML via the entity view builder, converts the HTML
   * to structured plain text, then assembles the output in three sections:
   * content metadata header, HTML body text, and entity context footer.
   *
   * Truncation to max_chars_per_request is applied after all sections are
   * joined, with a "[Content truncated for assessment]" notice appended.
   */
  public function extract(NodeInterface $node): string {
    $maxChars = $this->getMaxChars();

    // Build surrounding context blocks from the trait.
    $metadata = $this->buildContentMetadataBlock($node);
    $entityContext = $this->buildEntityContextBlock($node);

    $html = $this->renderFullHtmlPage($node);

    if (empty(trim($html))) {
      // Nothing rendered; assemble header and footer without body.
      $parts = array_filter(
        [$metadata, $entityContext],
        fn(string $p): bool => $p !== ''
      );
      return implode("\n\n", $parts);
    }

    // Convert rendered HTML to structured plain text.
    $bodyText = $this->convertHtmlToStructuredText($html);

    $parts = array_filter(
      [$metadata, $bodyText, $entityContext],
      fn(string $p): bool => $p !== ''
    );
    $content = implode("\n\n", $parts);

    // Truncate to the configured character limit if necessary.
    if (mb_strlen($content) > $maxChars) {
      $content = mb_substr($content, 0, $maxChars) . "\n[Content truncated for assessment]";
    }

    return $content;
  }

  /**
   * Renders a node as an HTML page using the active frontend theme.
   *
   * Creates a fake request and route to let Drupal build the block layout
   * and render the full frontend page structure (Header, Footer, attachments).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to render.
   *
   * @return string
   *   The fully rendered HTML page content.
   */
  protected function renderFullHtmlPage(NodeInterface $node): string {
    // Get the frontend theme set as the default.
    // This is the theme we want to use to render the node, so markup matches.
    $default_fe_theme = $this->configFactory->get('system.theme')->get('default');
    // Get the 'current' active theme, since the extractor runs on the backend,
    // we need to switch back to this one, once we've got the rendered HTML.
    $admin_active_theme = $this->themeManager->getActiveTheme();
    // Temporary set the active frontend theme before rendering the node, so the
    // extracted HTML matches the markup editors are viewing.
    $frontend_theme = $this->themeInitialization->getActiveThemeByName($default_fe_theme);
    $this->themeManager->setActiveTheme($frontend_theme);

    try {
      // Create a new Route object for the node view.
      $route = new Route('/node/{node}', [
        '_controller' => '\Drupal\node\Controller\NodeViewController::view',
        '_title_callback' => '\Drupal\node\Controller\NodeViewController::title',
      ]);

      // We fake a routeMatch object for the current node, since the current
      // routeMatch is for the backend route.
      $fake_route_match = new RouteMatch(
        'entity.node.canonical',
        $route,
        ['node' => $node],
        ['node' => $node->id()]
      );

      // We create a fake (node) request object to pass into the renderer.
      // Since the actual request comes from the backend the "frontend" assets
      // (like regions, metadata, etc... are not present).
      $fake_request = $this->currentRequest->duplicate();
      $fake_request->attributes->set('_route_object', $route);
      $fake_request->attributes->set('_route', 'entity.node.canonical');
      $fake_request->attributes->set('node', $node);

      // Build node render array.
      $view_builder = $this->entityTypeManager->getViewBuilder('node');
      $main_content = $view_builder->view($node, 'full');
      $main_content['#title'] = $node->label();

      // If metatag module is installed, we extract the metatags and attach them
      // to the rendered node.
      if ($this->moduleHandler->moduleExists('metatag')) {
        $this->attachMetatags($main_content, $node);
      }

      $html_renderer = $this->mainContentHtmlRenderer;

      // Finally create a new RenderContext wrapper to send the rendered node
      // array with all attachments along with our fake request and route.
      $render_context = new RenderContext();
      $response = $this->renderer->executeInRenderContext(
        $render_context, function () use ($main_content, $fake_request, $fake_route_match, $html_renderer) {
          return $html_renderer->renderResponse($main_content, $fake_request, $fake_route_match);
        }
      );

      // Update render context with the added metadata.
      if (!$render_context->isEmpty()) {
        $bubbleable_metadata = $render_context->pop();
        if ($response instanceof HtmlResponse) {
          $response->addCacheableDependency($bubbleable_metadata);
        }
      }
      // Process the rest of attachments (coming from core).
      if ($response instanceof HtmlResponse) {
        $processed_response = $this->attachmentsProcessor->processAttachments($response);
        $html_output = $processed_response instanceof HtmlResponse ? (string) $processed_response->getContent() : '';
      }
      else {
        $html_output = '';
      }

    }
    finally {
      // Switch back to the admin theme.
      $this->themeManager->setActiveTheme($admin_active_theme);
    }

    return $html_output;
  }

  /**
   * If Metatag module is enabled, we append metatags to the rendered array.
   *
   * @param array &$renderArray
   *   The target render array to attach the tags to.
   * @param \Drupal\node\NodeInterface $node
   *   The active node entity with the tokens.
   */
  protected function attachMetatags(array &$renderArray, NodeInterface $node): void {
    // Get all metatags for this node.
    $metatags = $this->metatagManager->tagsFromEntityWithDefaults($node);
    // Convert tokens.
    $metatagAttachments = $this->metatagManager->generateElements($metatags, $node);
    // Merge the attachments in the render array.
    if (!empty($metatagAttachments)) {
      $renderArray = array_merge_recursive($renderArray, $metatagAttachments);
    }
  }

  /**
   * Converts HTML to structured plain text (headings, lists, tables, links).
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

    // Meta values live in attributes, not text nodes; collect before stripping.
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
    $text = (string) preg_replace('/[^\S\n]+/', ' ', $text);
    // Collapse space-only lines so consecutive newline runs normalize.
    $text = (string) preg_replace('/^ +$/m', '', $text);
    // Collapse runs of three or more consecutive newlines to at most two.
    $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

    if (!empty($metaLines)) {
      $metaBlock = "--- Page Meta Tags ---\n" . implode("\n", $metaLines);
      $text = $metaBlock . "\n\n" . $text;
    }

    return trim($text);
  }

  /**
   * Extracts meta tag values from the document head.
   *
   * Uses the name, property, or http-equiv attribute as the label and the
   * content attribute as the value. Formatted as "Meta {label}: {value}".
   *
   * @param \DOMXPath $xpath
   *   The XPath object for the current document.
   *
   * @return string[]
   *   The formatted meta tag lines.
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
      $label = $node->getAttribute('name') ?: $node->getAttribute('property') ?: $node->getAttribute('http-equiv');
      $label = trim($label);
      $value = trim($node->getAttribute('content'));

      if ($label === '' || $value === '') {
        continue;
      }

      // Skip common non-content metas.
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
   * Removes <script>, <style>, <nav>, <header>, <footer>, and any element
   * whose class attribute contains "visually-hidden" or "hidden" so that
   * Drupal theme chrome and screen-reader-only text do not pollute the
   * LLM-facing content.
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
    /** @var \DOMElement $node */
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
    /** @var \DOMElement $node */
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
   * Replaces ul/ol lists with plain-text bullets or numbering.
   *
   * Ordered lists are processed before unordered lists.
   *
   * @param \DOMXPath $xpath
   *   The XPath object for the current document.
   */
  protected function convertLists(\DOMXPath $xpath): void {
    // Ordered lists first (collect before DOM mutations).
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

    // Unordered lists.
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
   *   The limit from configuration, or 8000 when unset or invalid.
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
   *   The hostname, or an empty string when there is no current request.
   */
  protected function getSiteHost(): string {
    $request = $this->currentRequest;
    return $request->getHost() ?: '';
  }

}
