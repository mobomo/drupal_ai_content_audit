<?php

/**
 * @file
 * Document hooks provided by the AI Content Audit module.
 */

/**
 * Alter the AIRO analysis side panel render array.
 *
 * @param array $build
 *   Panel render array built by
 *   \Drupal\ai_content_audit\Service\AiroAnalysisPanelBuilder::build().
 * @param \Drupal\node\NodeInterface $node
 *   The node being analyzed.
 */
function hook_airo_analysis_panel_alter(array &$build, \Drupal\node\NodeInterface $node): void {
}

/**
 * Alter the AIRO Preview tab render array.
 *
 * This hook allows optional modules to extend the AIRO Preview tab without
 * requiring the base AI Content Audit module to depend on those modules.
 *
 * The render array includes neutral insertion points for common extension
 * needs. For page-skin landing content, append render arrays to:
 *
 * @code
 * $build['#after_landing_content'][] = [
 *   '#type' => 'container',
 *   '#attributes' => ['class' => ['my-preview-extension']],
 *   'message' => [
 *     '#plain_text' => 'Additional preview content.',
 *   ],
 * ];
 * @endcode
 *
 * Implementations may also alter cacheability metadata as needed.
 *
 * @param array $build
 *   AI Preview tab render array built by
 *   \Drupal\ai_content_audit\Service\AiroPreviewTabBuilder::build().
 * @param \Drupal\node\NodeInterface $node
 *   The node being previewed.
 * @param bool $page_skin
 *   TRUE when the tab is rendered inside the AIRO page-skin panel.
 */
function hook_airo_preview_tab_alter(array &$build, \Drupal\node\NodeInterface $node, bool $page_skin): void {
}
