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
 * Extracts node content as structured plain text from rendered HTML.
 *
 * Parses HTML with DOMDocument, normalises headings, links, images, lists, and
 * tables into plain-text markers, and prepends metadata from
 * EntityContextTrait.
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
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\ai_content_audit\Service\LayoutBuilderPreviewSource $layoutBuilderPreviewSource
   *   The Layout Builder preview source.
   * @param \Drupal\ai_content_audit\Service\HtmlFetchService $htmlFetchService
   *   The HTML fetch service.
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
   * Tries HTTP fetch of the canonical URL (with session cookies), then PHP
   * rendering, then plain text from Layout Builder inline block entities.
   */
  public function extract(NodeInterface $node): string {
    $maxChars = $this->getMaxChars();

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
   * Returns body text using the HTTP, render, then inline-block fallbacks.
   */
  private function extractBodyText(NodeInterface $node): string {
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

    $inlineText = $this->layoutBuilderPreviewSource->extractTextFromInlineBlocks($node, 'full');
    if ($inlineText !== '') {
      return $inlineText;
    }

    return '';
  }

  /**
   * Fetches canonical page HTML, forwarding the current request cookies.
   */
  private function fetchNodeHtmlViaHttp(NodeInterface $node): string {
    try {
      $url = $node->toUrl('canonical')->setAbsolute(TRUE)->toString();
    }
    catch (\Throwable) {
      return '';
    }

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
   * Renders the node with Layout Builder sections when enabled.
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
   * Converts HTML to structured plain text (headings, lists, tables, links).
   *
   * @param string $html
   *   The HTML to convert.
   *
   * @return string
   *   The structured text, or an empty string when nothing can be extracted.
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

    // Strip navigation chrome, scripts, styles, and visually-hidden elements.
    $this->stripUnwantedElements($xpath);

    // Tables before lists/images/links; inner tables first (reverse order).
    $this->convertTables($xpath);
    $this->convertLists($xpath);
    $this->convertImages($xpath);
    $this->convertLinks($xpath, $this->getSiteHost());
    $this->convertHeadings($xpath);

    $text = $doc->textContent;

    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $text = (string) preg_replace('/[^\S\n]+/', ' ', $text);

    // Lines that are only spaces collapse so consecutive newline runs normalize.
    $text = (string) preg_replace('/^ +$/m', '', $text);

    // Collapse runs of three or more consecutive newlines to at most two.
    $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

    $text = trim($text);

    if (!empty($metaLines)) {
      $metaBlock = "--- Page Meta Tags ---\n" . implode("\n", $metaLines);
      $text = $metaBlock . "\n\n" . $text;
    }

    return $text;
  }

  /**
   * Fallback when structured conversion returns an empty string.
   */
  protected function convertHtmlToPlainTextFallback(string $html): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string) preg_replace('/[^\S\n]+/', ' ', $text);
    $text = (string) preg_replace('/^ +$/m', '', $text);
    $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
  }

  /**
   * Extracts meta tag values from the document head.
   *
   * Uses the name, property, or http-equiv attribute as the label and the
   * content attribute as the value. Each line is formatted as "Meta {label}: {value}".
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
      $label = $node->getAttribute('name')
        ?: $node->getAttribute('property')
        ?: $node->getAttribute('http-equiv');
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
   * Removes scripts, styles, nav, and visually hidden nodes from the DOM.
   *
   * Does not remove header or footer; Layout Builder output may place content
   * there.
   *
   * @param \DOMXPath $xpath
   *   The XPath object for the current document.
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
      // DOMNodeList is live; copy nodes before removing.
      $nodes = iterator_to_array($xpath->query($query) ?: [], FALSE);
      foreach ($nodes as $node) {
        if ($node->parentNode !== NULL) {
          $node->parentNode->removeChild($node);
        }
      }
    }
  }

  /**
   * Replaces h1–h6 elements with plain-text heading markers.
   *
   * @param \DOMXPath $xpath
   *   The XPath object for the current document.
   */
  protected function convertHeadings(\DOMXPath $xpath): void {
    for ($level = 1; $level <= 6; $level++) {
      // DOMNodeList is live.
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
   * Replaces img elements with alt-text markers.
   *
   * @param \DOMXPath $xpath
   *   The XPath object for the current document.
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
   * Replaces anchor elements with internal/external link markers.
   *
   * @param \DOMXPath $xpath
   *   The XPath object for the current document.
   * @param string $siteHost
   *   The current request host, or empty string when unavailable (e.g. CLI).
   */
  protected function convertLinks(\DOMXPath $xpath, string $siteHost): void {
    $nodes = iterator_to_array($xpath->query('//a[@href]') ?: [], FALSE);
    foreach ($nodes as $node) {
      $href = trim($node->getAttribute('href'));
      $text = trim($node->textContent);
      if ($text === '') {
        $text = $href;
      }

      // Path-relative, fragment-only, or same host counts as internal.
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
   * Replaces table elements with a compact text grid.
   *
   * Processes tables in reverse document order so nested tables are handled
   * before parents.
   *
   * @param \DOMXPath $xpath
   *   The XPath object for the current document.
   */
  protected function convertTables(\DOMXPath $xpath): void {
    // Innermost tables first.
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
   * Returns the maximum characters allowed per extracted content payload.
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
   * Returns the current request host for link classification.
   *
   * @return string
   *   The hostname, or an empty string when there is no current request.
   */
  protected function getSiteHost(): string {
    $request = $this->requestStack->getCurrentRequest();
    return $request !== NULL ? $request->getHost() : '';
  }

}
