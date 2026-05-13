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
 * Extracts plain text from node fields on the configured view display.
 *
 * Prepends metadata and appends entity context via EntityContextTrait.
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
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
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
   * Compiles extractable field values for the node (body of extract() output).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $view_mode
   *   The view mode used to resolve the entity view display.
   *
   * @return string
   *   Joined field text, or an empty string.
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
   * Loads the entity view display for the bundle and view mode.
   *
   * @param string $bundle
   *   The node bundle.
   * @param string $view_mode
   *   The view mode.
   *
   * @return object|null
   *   The display, or NULL.
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
   * Plain text for one field (HTML field types use convertAndStripHtml()).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $field_name
   *   The field name.
   * @param string $field_type
   *   The field type plugin ID.
   *
   * @return string
   *   Extracted text.
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
   * Normalises headings, images, and links to plain-text markers, then strips tags.
   *
   * @param string $html
   *   HTML from a rich text field.
   *
   * @return string
   *   Plain text with markers preserved.
   */
  protected function convertAndStripHtml(string $html): string {
    // Headings to "# H1: …" style markers.
    $html = preg_replace_callback(
      '/<h([1-6])[^>]*>(.*?)<\/h\1>/si',
      function (array $m): string {
        $level = (int) $m[1];
        $text = strip_tags($m[2]);
        return "\n" . str_repeat('#', $level) . " H{$level}: " . $text . "\n";
      },
      $html
    );

    // Images with alt, then images without alt.
    $html = preg_replace_callback(
      '/<img[^>]+alt=["\']([^"\']*)["\'][^>]*\/?>/i',
      function (array $m): string {
        $alt = trim($m[1]);
        return !empty($alt) ? " [Image: {$alt}] " : ' [Image: no alt text] ';
      },
      $html
    );

    $html = (string) preg_replace('/<img(?![^>]*\balt=)[^>]*\/?>/i', ' [Image: no alt text] ', $html);

    // Anchors to link markers.
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

    return $this->stripHtml($html);
  }

  /**
   * Strips tags, decodes entities, and normalises whitespace.
   *
   * @param string $html
   *   HTML or mostly plain text.
   *
   * @return string
   *   Plain text.
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
