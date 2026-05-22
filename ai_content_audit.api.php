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
