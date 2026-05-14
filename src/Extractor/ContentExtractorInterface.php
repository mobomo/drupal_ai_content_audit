<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Extractor;

use Drupal\ai_content_audit\Enum\RenderMode;
use Drupal\node\NodeInterface;

/**
 * Defines the interface for content extractor plugins used in AI assessment.
 *
 * A content extractor is responsible for producing a string representation
 * of a node's content suitable for submission to an AI language or vision model.
 *
 * Implementations are annotation-based plugins discovered automatically from
 * any module's Plugin/ContentExtractor/ subdirectory. They are annotated with
 * @ContentExtractor and managed by ContentExtractorManager.
 *
 * To add a new extractor:
 * 1. Optionally add a case to \Drupal\ai_content_audit\Enum\RenderMode.
 * 2. Create a class in Plugin/ContentExtractor/ implementing this interface.
 * 3. Annotate it with @ContentExtractor (id, label, description, render_mode).
 * No changes to AiAssessmentService, ContentExtractorManager, or services.yml
 * are required — the plugin system handles discovery automatically.
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
   * Extracts content from the given node for AI assessment.
   *
   * For text and HTML modes, returns a UTF-8 string of the content.
   * For screenshot mode, returns a base64-encoded PNG data URI or an
   * absolute file path to the screenshot image.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to extract content from.
   *
   * @return string
   *   The extracted content as a string. Must not be empty.
   *
   * @throws \RuntimeException
   *   If content extraction fails.
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
