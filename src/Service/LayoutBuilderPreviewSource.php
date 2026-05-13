<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\LayoutEntityHelperTrait;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves Layout Builder section storage and builds LB render arrays for a node.
 *
 * Layout extraction assumes the passed {@see \Drupal\node\NodeInterface} is the
 * revision that should be assessed (e.g. the default revision on the canonical
 * route, or a specific revision loaded via
 * {@see \Drupal\Core\Entity\RevisionableStorageInterface::loadRevision()}).
 *
 * @see \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay::buildSections()
 */
final class LayoutBuilderPreviewSource {

  use LayoutEntityHelperTrait;

  public function __construct(
    SectionStorageManagerInterface $section_storage_manager,
    protected ContextRepositoryInterface $contextRepository,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->sectionStorageManager = $section_storage_manager;
  }

  /**
   * Wraps the trait's protected storage resolver for tests and diagnostics.
   */
  public function getSectionStorageForNode(NodeInterface $node, string $view_mode = 'full'): ?SectionStorageInterface {
    return $this->getSectionStorageForEntity($node, $view_mode);
  }

  /**
   * Returns layout sections for the node revision (same semantics as core trait).
   *
   * @return \Drupal\layout_builder\Section[]
   */
  public function getSectionsForNode(NodeInterface $node, string $view_mode = 'full'): array {
    return $this->getEntitySections($node, $view_mode);
  }

  /**
   * Builds a render array of all Layout Builder sections for the given node.
   *
   * Mirrors {@see \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay::buildSections()}
   * so block/field components resolve with the same contexts as the entity view.
   *
   * @return array<int|string, mixed>
   *   A numerically keyed render array (one element per section delta), or an
   *   empty array when Layout Builder is not enabled for this display/view mode.
   */
  public function buildSectionsRenderArray(NodeInterface $node, string $view_mode = 'full'): array {
    if (!$node instanceof FieldableEntityInterface) {
      return [];
    }

    $display = EntityViewDisplay::collectRenderDisplay($node, $view_mode);
    if (!$display instanceof LayoutEntityDisplayInterface || !$display->isLayoutBuilderEnabled()) {
      return [];
    }

    $available_context_ids = array_keys($this->contextRepository->getAvailableContexts());
    $contexts = [
      'view_mode' => new Context(new ContextDefinition('string'), $view_mode),
      'entity' => EntityContext::fromEntity($node),
      'display' => EntityContext::fromEntity($display),
    ] + $this->contextRepository->getRuntimeContexts($available_context_ids);

    $label = new TranslatableMarkup('@entity being viewed', [
      '@entity' => $node->getEntityType()->getSingularLabel(),
    ]);
    $contexts['layout_builder.entity'] = EntityContext::fromEntity($node, $label);

    $cacheability = new CacheableMetadata();
    $storage = $this->sectionStorageManager->findByContext($contexts, $cacheability);
    if (!$storage) {
      return [];
    }

    $build = [];
    foreach ($storage->getSections() as $delta => $section) {
      $build[$delta] = $section->toRenderArray($contexts, FALSE);
    }
    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * Extracts plain text from inline_block components in the node's LB sections.
   *
   * This is a reliable fallback for headless PHP rendering contexts (e.g. CLI /
   * Drush) where InlineBlock::build() may return empty arrays because block_content
   * entities fail access checks without a real HTTP request / authenticated session.
   *
   * The method directly loads each block_content entity revision referenced by
   * inline_block component plugins and extracts plain text from their fields.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose LB layout should be traversed.
   * @param string $view_mode
   *   The view mode to inspect.
   *
   * @return string
   *   Concatenated plain text from all inline block fields, or an empty string
   *   if no inline blocks are found or the node has no LB override.
   */
  public function extractTextFromInlineBlocks(NodeInterface $node, string $view_mode = 'full'): string {
    if (!$node->hasField('layout_builder__layout')) {
      return '';
    }

    $lbField = $node->get('layout_builder__layout');
    if ($lbField->isEmpty()) {
      return '';
    }

    $blockContentStorage = $this->entityTypeManager->getStorage('block_content');
    $parts = [];

    foreach ($lbField->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $config = $component->get('configuration');
        $pluginId = $config['id'] ?? '';

        // Only handle inline_block plugins — field_block / extra_field_block
        // are node fields already captured by normal entity rendering.
        if (!str_starts_with($pluginId, 'inline_block:')) {
          continue;
        }

        $revisionId = $config['block_revision_id'] ?? NULL;
        if (!$revisionId) {
          continue;
        }

        try {
          /** @var \Drupal\block_content\BlockContentInterface|null $blockContent */
          $blockContent = $blockContentStorage->loadRevision((int) $revisionId);
        }
        catch (\Throwable) {
          continue;
        }

        if (!$blockContent instanceof FieldableEntityInterface) {
          continue;
        }

        foreach ($blockContent->getFields() as $fieldName => $fieldList) {
          $text = $this->extractFieldText($fieldList);
          if ($text !== '') {
            $parts[] = $text;
          }
        }
      }
    }

    return implode("\n\n", $parts);
  }

  /**
   * Field types that contain human-readable text content for LLM consumption.
   *
   * All other field types (integer, boolean, list_string, entity_reference,
   * file, image, link, etc.) are skipped to avoid sending configuration values
   * such as color schemes, layout choices, timestamps, or IDs to the LLM.
   */
  private const TEXT_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
  ];

  /**
   * Base field names that are always skipped regardless of type.
   *
   * These are Drupal entity system fields that carry no editorial content.
   */
  private const SKIP_BASE_FIELDS = [
    'uuid', 'langcode', 'type', 'revision_id', 'id', 'status', 'info',
    'changed', 'created', 'reusable', 'default_langcode',
    'revision_translation_affected', 'revision_created', 'revision_user',
    'revision_log',
  ];

  /**
   * Extracts a plain-text string from a single field item list.
   *
   * Returns an empty string for non-text field types (integers, booleans,
   * select lists, entity references, files, etc.) so that configuration
   * values like color schemes, layout choices, and timestamps are not
   * included in the LLM-facing content.
   */
  private function extractFieldText(FieldItemListInterface $fieldList): string {
    if ($fieldList->isEmpty()) {
      return '';
    }

    $definition = $fieldList->getFieldDefinition();

    // Skip base fields that never contain editorial content.
    if (in_array($definition->getName(), self::SKIP_BASE_FIELDS, TRUE)) {
      return '';
    }

    // Only process fields whose type explicitly carries human-readable text.
    if (!in_array($definition->getType(), self::TEXT_FIELD_TYPES, TRUE)) {
      return '';
    }

    $texts = [];
    foreach ($fieldList as $item) {
      $values = $item->getValue();
      foreach (['processed', 'value', 'summary'] as $key) {
        if (!empty($values[$key]) && is_string($values[$key])) {
          $raw = strip_tags((string) $values[$key]);
          $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
          $raw = trim((string) preg_replace('/\s+/', ' ', $raw));
          if ($raw !== '') {
            $texts[] = $raw;
            break;
          }
        }
      }
    }

    return implode(' ', $texts);
  }

}
