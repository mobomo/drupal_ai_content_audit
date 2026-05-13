<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Extractor;

use Drupal\ai_content_audit\Enum\RenderMode;
use Drupal\node\NodeInterface;

/**
 * Interface for plugins that build a text (or image) payload from a node.
 *
 * @see \Drupal\ai_content_audit\Annotation\ContentExtractor
 * @see \Drupal\ai_content_audit\Enum\RenderMode
 * @see \Drupal\ai_content_audit\Extractor\ContentExtractorManager
 */
interface ContentExtractorInterface {

  /**
   * Returns whether this extractor supports the given render mode.
   *
   * @param string $mode
   *   A RenderMode enum value string (e.g., 'text', 'html', 'screenshot').
   *
   * @return bool
   *   TRUE if this extractor handles the given mode.
   */
  public function supports(string $mode): bool;

  /**
   * Extracts assessable content from the node.
   *
   * Text and HTML modes return UTF-8 strings. Screenshot mode may return a
   * data URI or filesystem path, depending on implementation.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   The extracted payload.
   *
   * @throws \RuntimeException
   *   When extraction fails.
   */
  public function extract(NodeInterface $node): string;

  /**
   * Returns the canonical render mode string this extractor handles.
   *
   * Must match a RenderMode enum value (e.g., RenderMode::TEXT->value).
   *
   * @return string
   *   The render mode string.
   */
  public function getMode(): string;

}
