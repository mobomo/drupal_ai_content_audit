<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\ContentExtractor;

use Drupal\ai_content_audit\Extractor\ContentExtractorInterface;
use Drupal\ai_content_audit\Extractor\EntityContextTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Text-based field content extractor.
 *
 * Extracts displayable text values from node fields for AI assessment.
 * Only fields that appear in the configured entity view display and whose
 * field type is extractable (string, text, etc.) are included.
 *
 * The extracted string is assembled in three sections:
 * 1. A "--- Content Metadata ---" header block (title, content type, dates,
 *    canonical URL) prepended via EntityContextTrait::buildContentMetadataBlock().
 * 2. The field-level content extracted from all displayable, extractable fields,
 *    with heading tags and image Alt attributes preserved as text markers via
 *    convertAndStripHtml() before HTML is stripped.
 * 3. An "--- Entity Context ---" footer block (author, taxonomy terms, entity
 *    reference counts) appended via EntityContextTrait::buildEntityContextBlock().
 *
 * @ContentExtractor(
 *   id = "field_text",
 *   label = @Translation("Field text extractor"),
 *   description = @Translation("Extracts text content from entity field values."),
 *   render_mode = "text"
 * )
 */
class FieldExtractor extends PluginBase implements ContentExtractorInterface, ContainerFactoryPluginInterface {

  use EntityContextTrait;

  /**
   * Field types considered text-based and extractable.
   */
  const EXTRACTABLE_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
    'list_string',
  ];

  /**
   * Constructs a FieldExtractor plugin instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
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
   *
   * Assembles extracted content in three sections:
   * - Content Metadata header block (title, dates, URL) prepended at the top.
   * - Field-level body content with structural HTML markers preserved.
   * - Entity Context footer block (author, taxonomy, references) appended at the end.
   *
   * Truncation to max_chars_per_request is applied after all sections are joined.
   */
  public function extract(NodeInterface $node): string {
    $metadata = $this->buildContentMetadataBlock($node);
    $body = $this->extractForNode($node);
    $context = $this->buildEntityContextBlock($node);

    $parts = array_filter([
      $metadata,
      $body,
      $context,
    ], fn(string $part): bool => $part !== '');

    return implode("\n\n", $parts);
  }

  /**
   * {@inheritdoc}
   */
  public function getMode(): string {
    return $this->pluginDefinition['render_mode'] ?? '';
  }

  /**
   * Extracts all displayable text from a node as a single string.
   *
   * This method is responsible only for the field-level body content; the
   * surrounding metadata and entity context blocks are added by extract().
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to extract content from.
   * @param string $view_mode
   *   The view mode to check display configuration against.
   *
   * @return string
   *   Compiled plain-text content from all extractable fields, or an empty
   *   string when no extractable field values are found.
   */
  public function extractForNode(NodeInterface $node, string $view_mode = 'default'): string {
    $parts = [];

    // Load the active entity view display to check which fields are shown.
    $display = $this->loadViewDisplay($node->bundle(), $view_mode);

    // Iterate over all node fields.
    foreach ($node->getFieldDefinitions() as $field_name => $definition) {
      // Skip computed, non-configurable, and internal fields.
      if ($field_name === 'title') {
        continue;
      }
      if (str_starts_with($field_name, 'revision_') || str_starts_with($field_name, 'status') || str_starts_with($field_name, 'uid')) {
        continue;
      }

      $field_type = $definition->getType();
      if (!in_array($field_type, self::EXTRACTABLE_FIELD_TYPES, TRUE)) {
        continue;
      }

      // Check if this field is configured in the display.
      if ($display && !$display->getComponent($field_name)) {
        continue;
      }

      $field = $node->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }

      // Extract text from each field item.
      $field_label = $definition->getLabel();
      $field_text = $this->extractFieldText($node, $field_name, $field_type);
      if (!empty(trim($field_text))) {
        $parts[] = $field_label . ': ' . $field_text;
      }
    }

    return implode("\n\n", $parts);
  }

  /**
   * Loads the entity view display for a given bundle and view mode.
   *
   * @param string $bundle
   *   The node bundle (content type).
   * @param string $view_mode
   *   The view mode machine name.
   *
   * @return object|null
   *   The entity view display, or NULL if not found.
   */
  protected function loadViewDisplay(string $bundle, string $view_mode): ?object {
    try {
      $storage = $this->entityTypeManager->getStorage('entity_view_display');
      $display = $storage->load('node.' . $bundle . '.' . $view_mode);
      return $display ?: $storage->load('node.' . $bundle . '.default');
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Extracts plain text from a specific field on a node.
   *
   * For HTML-capable field types (text, text_long, text_with_summary),
   * convertAndStripHtml() is used so that heading structure and image alt text
   * are preserved as LLM-readable markers before HTML tags are stripped.
   * Plain string types (string, string_long, list_string) are processed with
   * strip_tags() directly because their values do not contain HTML markup.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node containing the field.
   * @param string $field_name
   *   The machine name of the field.
   * @param string $field_type
   *   The field type string.
   *
   * @return string
   *   The extracted plain-text value(s) joined by a space.
   */
  protected function extractFieldText(NodeInterface $node, string $field_name, string $field_type): string {
    $field = $node->get($field_name);
    $texts = [];

    foreach ($field as $item) {
      switch ($field_type) {
        case 'text_with_summary':
          $value = $item->value ?? '';
          $summary = $item->summary ?? '';
          if (!empty($summary)) {
            $texts[] = $this->convertAndStripHtml($summary);
          }
          if (!empty($value)) {
            $texts[] = $this->convertAndStripHtml($value);
          }
          break;

        case 'text':
        case 'text_long':
          $value = $item->value ?? '';
          if (!empty($value)) {
            $texts[] = $this->convertAndStripHtml($value);
          }
          break;

        case 'string':
        case 'string_long':
        case 'list_string':
          $value = $item->value ?? '';
          if (!empty($value)) {
            $texts[] = strip_tags($value);
          }
          break;
      }
    }

    return implode(' ', array_filter($texts));
  }

  /**
   * Converts structural HTML elements to text markers, then strips remaining tags.
   *
   * This method is applied to field values that may contain rich HTML (text,
   * text_long, text_with_summary) to preserve structural signals for the LLM:
   *
   * - Heading elements (<h1>–<h6>) become markdown-style markers such as
   *   "# H1: Heading text" on their own lines, preserving content hierarchy.
   * - Image elements (<img>) become "[Image: alt text]" or "[Image: no alt text]"
   *   so image accessibility is visible to the LLM without the binary data.
   * - Anchor elements (<a href="...">) become "[Link: anchor text (href)]" so
   *   link presence and destinations are readable in plain text.
   * - All remaining HTML tags are stripped via the existing stripHtml() helper,
   *   which also decodes HTML entities and normalises whitespace.
   *
   * @param string $html
   *   The HTML string to process, typically a rich text field value.
   *
   * @return string
   *   Plain text with structural markers preserved and whitespace normalised.
   */
  protected function convertAndStripHtml(string $html): string {
    // Phase 1: Convert <h1>–<h6> to markdown-style heading markers.
    $html = preg_replace_callback(
      '/<h([1-6])[^>]*>(.*?)<\/h\1>/si',
      function (array $m): string {
        $level = (int) $m[1];
        $text = strip_tags($m[2]);
        return "\n" . str_repeat('#', $level) . " H{$level}: " . $text . "\n";
      },
      $html
    );

    // Phase 2a: Convert <img> tags that have an alt attribute.
    $html = preg_replace_callback(
      '/<img[^>]+alt=["\']([^"\']*)["\'][^>]*\/?>/i',
      function (array $m): string {
        $alt = trim($m[1]);
        return !empty($alt) ? " [Image: {$alt}] " : ' [Image: no alt text] ';
      },
      $html
    );

    // Phase 2b: Convert remaining <img> tags that have no alt attribute at all.
    $html = (string) preg_replace('/<img(?![^>]*\balt=)[^>]*\/?>/i', ' [Image: no alt text] ', $html);

    // Phase 3: Convert <a href="..."> anchors to [Link: text (href)] markers.
    $html = preg_replace_callback(
      '/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si',
      function (array $m): string {
        $href = trim($m[1]);
        $text = trim(strip_tags($m[2]));
        if ($text === '') {
          $text = $href;
        }
        return " [Link: {$text} ({$href})] ";
      },
      $html
    );

    // Phase 4: Strip all remaining tags, decode entities, and normalise
    // whitespace — reusing the existing stripHtml() helper.
    return $this->stripHtml($html);
  }

  /**
   * Strips HTML and normalizes whitespace from a string.
   *
   * This is the final normalisation step used by both convertAndStripHtml() for
   * rich-text fields and (indirectly) convertAndStripHtml()-free code paths.
   * Heading/image/link markers inserted by convertAndStripHtml() are preserved
   * because they do not contain HTML tags.
   *
   * @param string $html
   *   The HTML string to process.
   *
   * @return string
   *   Plain text with normalised whitespace.
   */
  protected function stripHtml(string $html): string {
    // Decode HTML entities first.
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Strip all remaining tags.
    $text = strip_tags($text);
    // Collapse multiple consecutive blank lines to at most two newlines.
    $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
    // Collapse inline runs of whitespace (spaces/tabs) but preserve newlines.
    $text = (string) preg_replace('/[^\S\n]+/', ' ', $text);
    return trim($text);
  }

}
