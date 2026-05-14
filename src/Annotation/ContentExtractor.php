<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Annotation for ContentExtractor plugins.
 *
 * Plugins live under Plugin/ContentExtractor/ and may be altered with
 * hook_content_extractor_info_alter().
 *
 * @see \Drupal\ai_content_audit\Extractor\ContentExtractorManager
 * @see \Drupal\ai_content_audit\Extractor\ContentExtractorInterface
 *
 * @Annotation
 */
class ContentExtractor extends Plugin {

  /**
   * The plugin ID.
   */
  public string $id;

  /**
   * The human-readable label of the plugin.
   */
  public string $label;

  /**
   * A brief description of the plugin.
   */
  public string $description;

  /**
   * The render mode this extractor handles.
   *
   * Must correspond to a RenderMode enum value (e.g., 'text', 'html',
   * 'screenshot').
   *
   * @see \Drupal\ai_content_audit\Enum\RenderMode
   */
  public string $render_mode;

}
