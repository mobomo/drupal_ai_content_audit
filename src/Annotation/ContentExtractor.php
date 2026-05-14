<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a Content Extractor plugin annotation object.
 *
 * Plugins implementing this annotation are discovered automatically from
 * the Plugin/ContentExtractor/ subdirectory of any module.
 *
 * Other modules may alter plugin definitions via
 * hook_content_extractor_info_alter(&$definitions).
 *
 * Example usage:
 * @code
 * @ContentExtractor(
 *   id = "field_text",
 *   label = @Translation("Field text extractor"),
 *   description = @Translation("Extracts text content from entity field values."),
 *   render_mode = "text"
 * )
 * @endcode
 *
 * @see \Drupal\ai_content_audit\Extractor\ContentExtractorManager
 * @see \Drupal\ai_content_audit\Extractor\ContentExtractorInterface
 * @see plugin_api
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
