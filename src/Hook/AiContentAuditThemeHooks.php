<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook_theme() implementations for AIRO Preview templates.
 */
final class AiContentAuditThemeHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme($existing, $type, $theme, $path): array {
    return [
      'ai_airo_panel' => [
        'variables' => [
          'node_id' => NULL,
          'revision_id' => NULL,
          'score' => NULL,
          'node_title' => NULL,
          'is_analyzing' => FALSE,
          'active_tab' => 'preview-tab',
          'tab_definitions' => [],
          'tab_panes' => [],
          'show_assessment_actions' => FALSE,
        ],
        'template' => 'ai-airo-panel',
      ],
      'ai_airo_accordion_item' => [
        'variables' => [
          'node_id' => NULL,
          'revision_id' => NULL,
          'score' => NULL,
          'node_title' => NULL,
          'is_analyzing' => FALSE,
          'active_tab' => 'preview-tab',
          'tab_definitions' => [],
          'tab_panes' => [],
          'show_assessment_actions' => FALSE,
          'assess_url' => NULL,
          'full_report_url' => NULL,
          'use_page_skin' => FALSE,
          'close_url' => NULL,
          'logo_url' => NULL,
        ],
        'template' => 'ai-airo-accordion-item',
      ],
      'ai_preview_tab' => [
        'variables' => [
          'use_page_skin' => FALSE,
          'model_choices' => [],
          'selected_keys' => [],
          'has_permission' => FALSE,
          'suggested_prompts' => [],
          'node_id' => NULL,
          'revision_id' => NULL,
          'query_url' => NULL,
          'providers_url' => NULL,
          'providers' => [],
          'active_provider' => NULL,
        ],
        'template' => 'ai-preview-tab',
      ],
      'airo_analysis_node_page' => [
        'variables' => [
          'node' => NULL,
          'node_render' => [],
          'analysis_panel' => [],
          'has_layout_builder' => FALSE,
        ],
        'template' => 'airo-analysis-node-page',
      ],
    ];
  }

}
