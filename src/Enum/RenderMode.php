<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Enum;

/**
 * Defines the content render modes available for AI assessment.
 *
 * The render mode controls how node content is extracted and presented
 * to the AI model for quality assessment:
 *
 * - TEXT: Raw text extracted from content fields (current default).
 *   Fast, synchronous, works with all AI models.
 *
 * - HTML: Rendered HTML of the node content area (entity view builder output).
 *   Richer than plain text; includes formatter output, links, image tags.
 *   Suitable for models with large context windows.
 *
 * - SCREENSHOT: A screenshot image of the full rendered page.
 *   Requires a headless browser (e.g., spatie/browsershot + Puppeteer).
 *   Must only run asynchronously via the queue worker.
 *   Requires a vision-capable AI model.
 *
 * @see \Drupal\ai_content_audit\Extractor\ContentExtractorInterface
 * @see \Drupal\ai_content_audit\Extractor\ContentExtractorManager
 */
enum RenderMode: string {

  /**
   * Extract plain text from content fields directly.
   */
  case TEXT = 'text';

  /**
   * Extract the rendered HTML of the node content area.
   *
   * Uses EntityViewBuilderInterface + RendererInterface.
   * Does NOT include blocks, regions, or theme chrome — node content only.
   */
  case HTML = 'html';

  /**
   * Capture a full-page screenshot of the rendered node.
   *
   * Requires Node.js + Puppeteer and spatie/browsershot on the server.
   * Must only be used asynchronously via the queue worker.
   */
  case SCREENSHOT = 'screenshot';

  /**
   * Returns the default render mode.
   */
  public static function default(): self {
    return self::TEXT;
  }

}
