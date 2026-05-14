<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Extractor;

use Drupal\node\NodeInterface;

/**
 * Provides entity relationship and metadata context extraction for content extractors.
 *
 * Classes using this trait gain two protected helper methods:
 *
 * - buildContentMetadataBlock(): returns the "--- Content Metadata ---" header
 *   block containing title, content type, dates, and canonical URL — intended
 *   to be prepended to any extracted content string before it is sent to the LLM.
 *
 * - buildEntityContextBlock(): returns the "--- Entity Context ---" footer block
 *   containing author display name, taxonomy term values per reference field, and
 *   counts of other entity reference fields — intended to be appended to extracted
 *   content after the main body text.
 *
 * Both methods are safe to call even when entity references are empty; they always
 * return a non-empty string with at least the section header line.
 *
 * Usage:
 * @code
 *   use EntityContextTrait;
 *
 *   public function extract(NodeInterface $node): string {
 *     $metadata = $this->buildContentMetadataBlock($node);
 *     $content  = $this->extractBody($node);
 *     $context  = $this->buildEntityContextBlock($node);
 *     return $metadata . "\n\n" . $content . "\n\n" . $context;
 *   }
 * @endcode
 *
 * @see \Drupal\ai_content_audit\Plugin\ContentExtractor\FieldExtractor
 */
trait EntityContextTrait {

  /**
   * Builds the Content Metadata header block for prepending to extracted content.
   *
   * The block contains the node title, human-readable content type label, creation
   * date, last-modified date (both formatted as Y-m-d), and the canonical URL path.
   * This information allows the LLM to assess content freshness and URL structure
   * without requiring any additional API calls.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose metadata should be included.
   *
   * @return string
   *   A multi-line string beginning with "--- Content Metadata ---".
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
   * Builds the Entity Context footer block for appending to extracted content.
   *
   * The block contains:
   * - The display name of the node owner (author), or "Anonymous" when the owner
   *   cannot be resolved.
   * - For each entity_reference or entity_reference_revisions field whose target
   *   type is taxonomy_term: the field label followed by a comma-separated list of
   *   term names.
   * - For each entity_reference field whose target type is neither taxonomy_term
   *   nor user: the field label prefixed with "Related " followed by an item count.
   *
   * Fields that are empty or reference users are skipped to keep the block concise.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose entity relationships and authorship should be included.
   *
   * @return string
   *   A multi-line string beginning with "--- Entity Context ---".
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
