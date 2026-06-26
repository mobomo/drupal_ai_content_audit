<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Hook;

use Drupal\ai_content_audit_scoring\Service\ScoreMetaBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Hook_theme() and preprocess implementations for AIRO Scoring templates.
 */
final class ScoringThemeHooks {

  public function __construct(
    private readonly ScoreMetaBuilder $scoreMetaBuilder,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly UrlGeneratorInterface $urlGenerator,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme($existing, $type, $theme, $path): array {
    $template_path = $this->moduleHandler
      ->getModule('ai_content_audit_scoring')
      ->getPath() . '/templates';

    return [
      'ai_assessment_panel' => [
        'variables' => [
          'score' => NULL,
          'suggestions' => [],
          'provider_id' => NULL,
          'model_id' => NULL,
          'created' => NULL,
        ],
        'path' => $template_path,
      ],
      'ai_inline_score_widget' => [
        'variables' => [
          'score' => NULL,
          'is_analyzing' => FALSE,
          'has_assessment' => FALSE,
          'high_priority_count' => 0,
          'node_id' => NULL,
          'revision_id' => NULL,
        ],
        'template' => 'ai-inline-score-widget',
        'path' => $template_path,
      ],
      'ai_score_tab' => [
        'variables' => [
          'score' => NULL,
          'qualitative_label' => NULL,
          'score_color' => NULL,
          'score_color_hex' => NULL,
          'trend_delta' => NULL,
          'donut_radius' => 50,
          'donut_circumference' => 0,
          'donut_offset' => 0,
          'sub_scores' => [],
          'checkpoints_by_category' => [],
          'history' => [],
          'node_id' => NULL,
          'revision_id' => NULL,
          'assess_url' => NULL,
          'readability_grade' => NULL,
          'tone' => NULL,
          'days_since_modified' => NULL,
          'is_stale' => FALSE,
          'h2_section_count' => NULL,
        ],
        'template' => 'ai-score-tab',
        'path' => $template_path,
      ],
      'ai_action_items_tab' => [
        'variables' => [
          'high_items' => [],
          'medium_items' => [],
          'low_items' => [],
          'action_items_status' => [],
          'total_count' => 0,
          'completed_count' => 0,
          'high_count' => 0,
          'node_id' => NULL,
          'revision_id' => NULL,
          'assess_url' => NULL,
        ],
        'template' => 'ai-action-items-tab',
        'path' => $template_path,
      ],
      'ai_action_item_card' => [
        'variables' => [
          'item' => [],
          'status' => NULL,
          'node_id' => NULL,
        ],
        'template' => 'ai-action-item-card',
        'path' => $template_path,
      ],
      'ai_technical_audit_tab' => [
        'variables' => [
          'checks' => [],
          'checks_grouped' => [],
          'pass_count' => 0,
          'total_count' => 0,
          'node_id' => NULL,
          'revision_id' => NULL,
          'filesystem_section' => NULL,
          'site_audit_url' => NULL,
        ],
        'template' => 'ai-technical-audit-tab',
        'path' => $template_path,
      ],
      'ai_filesystem_audit_section' => [
        'variables' => [
          'filesystem_categories' => [],
          'filesystem_summary' => [],
          'can_refresh' => FALSE,
          'node_id' => NULL,
        ],
        'template' => 'ai-filesystem-audit-section',
        'path' => $template_path,
      ],
      'ai_assessment_report' => [
        'variables' => [
          'assessment_id' => NULL,
          'node' => NULL,
          'node_edit_url' => NULL,
          'score' => NULL,
          'qualitative_status' => NULL,
          'trend_delta' => NULL,
          'created' => NULL,
          'provider_id' => NULL,
          'model_id' => NULL,
          'run_by' => NULL,
          'sub_scores' => [],
          'checkpoints' => [],
          'checkpoints_by_category' => [],
          'high_items' => [],
          'medium_items' => [],
          'low_items' => [],
          'action_items_status' => [],
          'completed_count' => 0,
          'total_action_items' => 0,
          'history' => [],
          'technical_checks' => [],
          'technical_checks_grouped' => [],
          'technical_pass_count' => 0,
          'technical_total_count' => 0,
          'filesystem_categories' => [],
          'filesystem_summary' => [],
          'readability' => [],
          'seo' => [],
          'content_completeness' => [],
          'tone_consistency' => [],
          'heading_hierarchy' => [],
          'image_accessibility' => [],
          'link_analysis' => [],
          'content_freshness' => [],
          'entity_richness' => [],
          'content_patterns' => [],
          'rag_chunk_quality' => [],
          'raw_output' => NULL,
          'result_json_formatted' => NULL,
        ],
        'template' => 'ai-assessment-report',
        'path' => $template_path,
      ],
    ];
  }

  /**
   * Implements hook_preprocess_HOOK() for ai_inline_score_widget.
   */
  #[Hook('preprocess_ai_inline_score_widget')]
  public function preprocessAiInlineScoreWidget(array &$variables): void {
    $score = (int) ($variables['score'] ?? 0);
    $meta = $this->scoreMetaBuilder->build($score);

    $variables['score_color'] = $meta['color'];
    $variables['status_label'] = $meta['label'];

    $radius = 28;
    $circumference = 2 * M_PI * $radius;
    $variables['donut_radius'] = $radius;
    $variables['donut_circumference'] = round($circumference, 4);
    $variables['donut_offset'] = round($circumference - ($score / 100) * $circumference, 4);

    $node_id = $variables['node_id'];
    if ($node_id) {
      try {
        $variables['assess_url'] = $this->urlGenerator->generateFromRoute(
          'ai_content_audit.panel.assess',
          ['node' => $node_id]
        );
      }
      catch (RouteNotFoundException) {
        $variables['assess_url'] = '';
      }
    }
    else {
      $variables['assess_url'] = '';
    }
    $variables['view_analysis_link'] = '';
  }

  /**
   * Implements hook_preprocess_HOOK() for ai_score_tab.
   */
  #[Hook('preprocess_ai_score_tab')]
  public function preprocessAiScoreTab(array &$variables): void {
    $score = (int) ($variables['score'] ?? 0);
    $meta = $this->scoreMetaBuilder->build($score);

    $variables['score_color'] = $meta['color'];
    $variables['score_color_hex'] = $meta['color_hex'];
    $variables['qualitative_label'] = $meta['label'];

    $radius = 50;
    $circumference = 2 * M_PI * $radius;
    $variables['donut_radius'] = $radius;
    $variables['donut_circumference'] = round($circumference, 4);
    $variables['donut_offset'] = round($circumference - ($score / 100) * $circumference, 4);

    foreach ($variables['sub_scores'] as &$sub) {
      if (!isset($sub['percentage'])) {
        $sub['percentage'] = $sub['max_score'] > 0
          ? round(($sub['score'] / $sub['max_score']) * 100)
          : 0;
      }
    }
    unset($sub);

    foreach ($variables['history'] as &$entry) {
      if (!isset($entry['bar_height'])) {
        $entry['bar_height'] = (int) $entry['score'];
      }
    }
    unset($entry);
  }

  /**
   * Implements hook_preprocess_HOOK() for ai_assessment_report.
   */
  #[Hook('preprocess_ai_assessment_report')]
  public function preprocessAiAssessmentReport(array &$variables): void {
    $score = (int) ($variables['score'] ?? 0);
    $meta = $this->scoreMetaBuilder->build($score);

    $variables['score_color'] = $meta['color'];
    $variables['score_color_hex'] = $meta['color_hex'];

    $variables['qualitative_label'] = $variables['qualitative_status'] ?? $meta['label'];

    $radius = 50;
    $circumference = 2 * M_PI * $radius;
    $variables['donut_radius'] = $radius;
    $variables['donut_circumference'] = round($circumference, 4);
    $variables['donut_offset'] = round($circumference - ($score / 100) * $circumference, 4);

    $created = (int) ($variables['created'] ?? 0);
    if ($created > 0) {
      $variables['assessment_date'] = $this->dateFormatter->format($created, 'long');
    }
    else {
      $variables['assessment_date'] = '';
    }

    foreach ($variables['sub_scores'] as &$sub) {
      if (!isset($sub['percentage'])) {
        $sub['percentage'] = $sub['max_score'] > 0
          ? round(($sub['score'] / $sub['max_score']) * 100)
          : 0;
      }
    }
    unset($sub);
  }

}
