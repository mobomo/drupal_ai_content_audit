<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for AI Content Assessment entities.
 *
 * Extending EntityViewsData causes parent::getViewsData() to auto-generate
 * Views handlers for every base field defined in
 * AiContentAssessment::baseFieldDefinitions(), including:
 *  - Numeric/string/date field handlers for id, label, score, result_json,
 *    raw_output, created, sub_scores, checkpoints, action_items,
 *    action_items_status, score_trend_delta, provider_id, model_id.
 *  - Relationship handlers for entity_reference fields target_node (→ node)
 *    and run_by (→ user), derived automatically from their target_type settings.
 *
 * No manual field or relationship definitions are needed here; the parent
 * class inspects the base field definitions and the DefaultTableMapping to
 * build the complete Views data array.
 */
class AiContentAssessmentViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();

    // All base fields and entity_reference relationships are generated
    // automatically by parent::getViewsData(). Add any custom overrides,
    // computed fields, or non-standard handlers below if needed in the future.

    return $data;
  }

}
