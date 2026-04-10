<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\ContentExtractor;

use Drupal\ai_content_audit\Extractor\ContentExtractorInterface;
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
 * @ContentExtractor(
 *   id = "field_text",
 *   label = @Translation("Field text extractor"),
 *   description = @Translation("Extracts text content from entity field values."),
 *   render_mode = "text"
 * )
 */
class FieldExtractor extends PluginBase implements ContentExtractorInterface, ContainerFactoryPluginInterface {

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
   */
  public function extract(NodeInterface $node): string {
    return $this->extractForNode($node);
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
   * @param \Drupal\node\NodeInterface $node
   *   The node to extract content from.
   * @param string $view_mode
   *   The view mode to check display configuration against.
   *
   * @return string
   *   Compiled plain-text content from all extractable fields.
   */
  public function extractForNode(NodeInterface $node, string $view_mode = 'default'): string {
    $parts = [];

    // Always include the title.
    $parts[] = 'Title: ' . $node->label();

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
            $texts[] = $this->stripHtml($summary);
          }
          if (!empty($value)) {
            $texts[] = $this->stripHtml($value);
          }
          break;

        case 'text':
        case 'text_long':
          $value = $item->value ?? '';
          if (!empty($value)) {
            $texts[] = $this->stripHtml($value);
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
   * Strips HTML and normalizes whitespace from a string.
   *
   * @param string $html
   *   The HTML string to process.
   *
   * @return string
   *   Plain text with normalized whitespace.
   */
  protected function stripHtml(string $html): string {
    // Decode HTML entities first.
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Strip all tags.
    $text = strip_tags($text);
    // Normalize whitespace.
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
  }

}
