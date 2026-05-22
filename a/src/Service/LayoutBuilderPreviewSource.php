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
 * Builds Layout Builder section render arrays for a node.
 *
 * Assumes the passed revision is the one to render (default or explicit).
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
   * Returns section storage for the node (public wrapper around the trait).
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
   * Builds a render array for each Layout Builder section.
   *
   * @return array<int|string, mixed>
   *   One render element per section delta, or an empty array when LB is off.
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
   * Plain text from inline_block components (fallback when PHP render is empty).
   *
   * Loads block_content revisions from the layout; useful in CLI where access
   * checks can skip unpublished inline blocks during render.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $view_mode
   *   Unused; kept for a stable public signature shared with render helpers.
   *
   * @return string
   *   Concatenated text fields, or an empty string when none apply.
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

        foreach ($blockContent->getFields() as $fieldList) {
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
   * Text field types included when reading inline block entities.
   */
  private const TEXT_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
  ];

  /**
   * Base fields on block_content that are never used as editorial text.
   */
  private const SKIP_BASE_FIELDS = [
    'uuid', 'langcode', 'type', 'revision_id', 'id', 'status', 'info',
    'changed', 'created', 'reusable', 'default_langcode',
    'revision_translation_affected', 'revision_created', 'revision_user',
    'revision_log',
  ];

  /**
   * Plain text from a field list (text types only; skips layout/config fields).
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
