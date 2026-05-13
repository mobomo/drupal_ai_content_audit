<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Enum;

/**
 * Render mode for node content extraction during assessment.
 *
 * @see \Drupal\ai_content_audit\Extractor\ContentExtractorInterface
 */
enum RenderMode: string {

  /**
   * Plain text from configured fields.
   */
  case TEXT = 'text';

  /**
   * Structured text from rendered HTML (see HtmlExtractor).
   */
  case HTML = 'html';

  /**
   * Full-page screenshot; intended for asynchronous queue processing only.
   */
  case SCREENSHOT = 'screenshot';

  /**
   * Returns the default render mode.
   */
  public static function default(): self {
    return self::TEXT;
  }

}
