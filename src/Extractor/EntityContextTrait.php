<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Extractor;

use Drupal\node\NodeInterface;

/**
 * Helpers to prepend metadata and append lightweight entity context to extracts.
 */
trait EntityContextTrait {

  /**
   * Builds the "--- Content Metadata ---" block (title, type, dates, URL).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   The metadata block.
   */
  protected function buildContentMetadataBlock(NodeInterface $node): string {
    // Resolve the canonical URL; fall back to /node/{nid} on any routing error.
    try {
      $url = $node->toUrl('canonical')->toString();
    }
    catch (\Exception $e) {
      $url = '/node/' . $node->id();
    }

    // Resolve the human-readable bundle label.
    $bundleEntity = $node->type->entity;
    $contentType = $bundleEntity ? (string) $bundleEntity->label() : $node->bundle();

    return implode("\n", [
      '--- Content Metadata ---',
      'Title: ' . (string) $node->label(),
      'Content Type: ' . $contentType,
      'Created: ' . date('Y-m-d', (int) $node->getCreatedTime()),
      'Last Modified: ' . date('Y-m-d', (int) $node->getChangedTime()),
      'URL: ' . $url,
    ]);
  }

  /**
   * Builds the "--- Entity Context ---" block (author, taxonomy, reference counts).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   The context block.
   */
  protected function buildEntityContextBlock(NodeInterface $node): string {
    $lines = ['--- Entity Context ---'];

    // Resolve author display name.
    $owner = $node->getOwner();
    $authorName = $owner ? $owner->getDisplayName() : 'Anonymous';
    $lines[] = 'Author: ' . $authorName;

    // Iterate all field definitions looking for entity reference fields.
    $taxonomyLines = [];
    $referenceLines = [];

    foreach ($node->getFieldDefinitions() as $fieldName => $fieldDef) {
      $fieldType = $fieldDef->getType();

      // Only process entity_reference and entity_reference_revisions fields.
      if (!in_array($fieldType, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
        continue;
      }

      $field = $node->get($fieldName);
      if ($field->isEmpty()) {
        continue;
      }

      $targetType = $fieldDef->getSetting('target_type');
      $fieldLabel = (string) $fieldDef->getLabel();

      if ($targetType === 'taxonomy_term') {
        // Load referenced terms and collect their labels.
        $termNames = [];
        foreach ($field->referencedEntities() as $entity) {
          $termNames[] = (string) $entity->label();
        }
        if (!empty($termNames)) {
          $taxonomyLines[] = $fieldLabel . ': ' . implode(', ', $termNames);
        }
      }
      elseif (!in_array($targetType, ['user', 'taxonomy_term'], TRUE)) {
        // For non-user, non-taxonomy references, report the count only.
        $count = $field->count();
        if ($count > 0) {
          $referenceLines[] = 'Related ' . $fieldLabel . ': ' . $count . ' items';
        }
      }
    }

    // Append taxonomy lines first, then other entity reference lines.
    $lines = array_merge($lines, $taxonomyLines, $referenceLines);

    return implode("\n", $lines);
  }

}
