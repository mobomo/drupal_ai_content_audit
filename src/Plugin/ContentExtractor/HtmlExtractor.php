<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\ContentExtractor;

use Drupal\ai_content_audit\Extractor\ContentExtractorInterface;
use Drupal\ai_content_audit\Extractor\EntityContextTrait;
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
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
    protected ConfigFactoryInterface $configFactory,
    protected RequestStack $requestStack,
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

    // Render the node to HTML using an isolated render context to prevent
    // "leaked render context" errors that occur when render calls are nested
    // outside an active render context.
    $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
    $renderArray = $viewBuilder->view($node, 'full');

    $html = (string) $this->renderer->executeInRenderContext(
      new RenderContext(),
      function () use ($renderArray): string {
        return (string) $this->renderer->render($renderArray);
      }
    );

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

    // Collapse runs of three or more consecutive newlines to at most two.
    $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

    // Collapse inline runs of horizontal whitespace (spaces/tabs) while
    // preserving the structural newlines added by the converters above.
    $text = (string) preg_replace('/[^\S\n]+/', ' ', $text);

    return trim($text);
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
      '//header',
      '//footer',
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
