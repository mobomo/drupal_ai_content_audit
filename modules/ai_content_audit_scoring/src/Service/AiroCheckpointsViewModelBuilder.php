<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Service;

use Drupal\ai_content_audit_scoring\Entity\AiContentAssessment;
use Drupal\ai_content_audit_scoring\Repository\AiContentAssessmentRepository;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Builds normalized data for the page-skin Checkpoints tab.
 */
final class AiroCheckpointsViewModelBuilder {

  use StringTranslationTrait;

  /**
   * Display order and score caps for the tab summary.
   */
  private const DIMENSIONS = [
    'technical_seo' => [
      'label' => 'Technical SEO',
      'max_score' => 40,
      'categories' => [
        'content structure',
        'metadata',
        'technical',
        'technical seo',
        'seo',
      ],
    ],
    'content_quality' => [
      'label' => 'Content Quality',
      'max_score' => 35,
      'categories' => [
        'accessibility',
        'content completeness',
        'content patterns',
        'content quality',
        'rag chunk quality',
      ],
    ],
    'schema_markup' => [
      'label' => 'Schema Markup',
      'max_score' => 25,
      'categories' => [
        'schema',
        'schema markup',
      ],
    ],
  ];

  public function __construct(
    private readonly AiContentAssessmentRepository $assessmentRepository,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Builds a template-ready model for a node.
   *
   * @return array<string, mixed>
   *   Normalized checkpoints tab data.
   */
  public function build(NodeInterface $node): array {
    $assessment = $this->assessmentRepository->getLatestForNode((int) $node->id());
    $hasAssessment = $assessment instanceof AiContentAssessment;
    $summaryItems = $this->buildSummaryItems($assessment);
    $sections = $this->buildSections($assessment, $summaryItems);

    return [
      'has_assessment' => $hasAssessment,
      'summary_items' => $summaryItems,
      'sections' => $sections,
      'last_check_label' => $this->buildLastCheckLabel($assessment),
      'node_id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'cache_tags' => $this->buildCacheTags($node, $assessment),
      'cache_contexts' => ['route', 'user.permissions'],
    ];
  }

  /**
   * Builds score summary rows keyed by dimension id.
   *
   * @return array<string, array<string, mixed>>
   *   Summary rows.
   */
  private function buildSummaryItems(?AiContentAssessment $assessment): array {
    $subScoresByDimension = [];
    $subScoresByLabel = [];

    if ($assessment instanceof AiContentAssessment) {
      foreach ($assessment->getSubScores() ?? [] as $subScore) {
        if (!is_array($subScore)) {
          continue;
        }
        $dimension = $this->normalizeKey((string) ($subScore['dimension'] ?? ''));
        $label = $this->normalizeKey((string) ($subScore['label'] ?? ''));
        if ($dimension !== '') {
          $subScoresByDimension[$dimension] = $subScore;
        }
        if ($label !== '') {
          $subScoresByLabel[$label] = $subScore;
        }
      }
    }

    $items = [];
    foreach (self::DIMENSIONS as $id => $definition) {
      $label = (string) $definition['label'];
      $subScore = $subScoresByDimension[$id]
        ?? $subScoresByLabel[$this->normalizeKey($label)]
        ?? NULL;
      $maxScore = (int) ($subScore['max_score'] ?? $definition['max_score']);
      $score = isset($subScore['score']) ? (int) $subScore['score'] : NULL;

      $items[$id] = [
        'id' => $id,
        'label' => $this->dimensionLabel($id),
        'score' => $score,
        'max_score' => $maxScore,
        'score_display' => $score === NULL ? '-/-' : $score . '/' . $maxScore,
        'percentage' => $score === NULL || $maxScore <= 0
          ? 0
          : max(0, min(100, (int) round(($score / $maxScore) * 100))),
      ];
    }

    return $items;
  }

  /**
   * Builds checkpoint sections keyed by dimension id.
   *
   * @param \Drupal\ai_content_audit_scoring\Entity\AiContentAssessment|null $assessment
   *   The latest assessment for the node, if one exists.
   * @param array<string, array<string, mixed>> $summaryItems
   *   Summary rows keyed by dimension id.
   *
   * @return array<int, array<string, mixed>>
   *   Checkpoint sections.
   */
  private function buildSections(?AiContentAssessment $assessment, array $summaryItems): array {
    $groupsByDimension = [];
    foreach (array_keys(self::DIMENSIONS) as $dimensionId) {
      $groupsByDimension[$dimensionId] = [];
    }

    if ($assessment instanceof AiContentAssessment) {
      foreach ($assessment->getCheckpoints() ?? [] as $checkpoint) {
        if (!is_array($checkpoint)) {
          continue;
        }

        $category = trim((string) ($checkpoint['category'] ?? 'Other'));
        $dimensionId = $this->dimensionForCategory($category);
        $groupKey = $this->normalizeKey($category);
        if (!isset($groupsByDimension[$dimensionId][$groupKey])) {
          $groupsByDimension[$dimensionId][$groupKey] = [
            'label' => $category !== '' ? $category : (string) $this->t('Other'),
            'items' => [],
          ];
        }

        $groupsByDimension[$dimensionId][$groupKey]['items'][] = $this->normalizeCheckpoint($checkpoint);
      }
    }

    $sections = [];
    foreach (self::DIMENSIONS as $dimensionId => $definition) {
      $groups = [];
      foreach ($groupsByDimension[$dimensionId] as $group) {
        $items = $this->sortCheckpoints($group['items']);
        $groups[] = [
          'label' => $group['label'],
          'items' => $items,
          'pass_count' => $this->countByStatus($items, 'pass'),
          'issue_count' => count($items) - $this->countByStatus($items, 'pass'),
        ];
      }

      $sections[] = [
        'id' => $dimensionId,
        'label' => $this->dimensionLabel($dimensionId),
        'summary' => $summaryItems[$dimensionId],
        'groups' => $groups,
        'is_open' => $dimensionId === 'technical_seo',
      ];
    }

    return $sections;
  }

  /**
   * Normalizes one checkpoint item.
   *
   * @param array<string, mixed> $checkpoint
   *   Raw checkpoint data from the assessment.
   *
   * @return array<string, string>
   *   Template-ready checkpoint data.
   */
  private function normalizeCheckpoint(array $checkpoint): array {
    $status = $this->normalizeStatus((string) ($checkpoint['status'] ?? 'info'));
    $priority = $this->normalizePriority((string) ($checkpoint['priority'] ?? 'low'));

    return [
      'label' => trim((string) ($checkpoint['item'] ?? $this->t('Untitled checkpoint'))),
      'status' => $status,
      'status_label' => $this->statusLabel($status),
      'priority' => $priority,
      'priority_label' => $this->priorityLabel($priority),
    ];
  }

  /**
   * Returns the dimension id for a checkpoint category.
   */
  private function dimensionForCategory(string $category): string {
    $normalized = $this->normalizeKey($category);
    foreach (self::DIMENSIONS as $dimensionId => $definition) {
      if (in_array($normalized, $definition['categories'], TRUE)) {
        return $dimensionId;
      }
    }

    return 'content_quality';
  }

  /**
   * Sorts checkpoints by urgency.
   *
   * @param array<int, array<string, string>> $items
   *   Checkpoint rows.
   *
   * @return array<int, array<string, string>>
   *   Sorted checkpoint rows.
   */
  private function sortCheckpoints(array $items): array {
    $statusWeight = [
      'fail' => 0,
      'warning' => 1,
      'info' => 2,
      'pass' => 3,
    ];
    $priorityWeight = [
      'high' => 0,
      'medium' => 1,
      'low' => 2,
    ];

    usort($items, static function (array $a, array $b) use ($statusWeight, $priorityWeight): int {
      return ($statusWeight[$a['status']] ?? 9) <=> ($statusWeight[$b['status']] ?? 9)
        ?: ($priorityWeight[$a['priority']] ?? 9) <=> ($priorityWeight[$b['priority']] ?? 9)
        ?: $a['label'] <=> $b['label'];
    });

    return $items;
  }

  /**
   * Counts checkpoints with a given status.
   *
   * @param array<int, array<string, string>> $items
   *   Checkpoint rows.
   * @param string $status
   *   The status to count.
   */
  private function countByStatus(array $items, string $status): int {
    return count(array_filter(
      $items,
      static fn(array $item): bool => ($item['status'] ?? '') === $status,
    ));
  }

  /**
   * Builds the latest assessment timestamp label.
   */
  private function buildLastCheckLabel(?AiContentAssessment $assessment): string {
    if (!$assessment instanceof AiContentAssessment) {
      return 'N/A';
    }

    $created = (int) ($assessment->get('created')->value ?? 0);
    if ($created <= 0) {
      return 'N/A';
    }

    return $this->dateFormatter->format($created, 'custom', 'Y-m-d - g:i a');
  }

  /**
   * Builds cache tags for the tab.
   *
   * @return string[]
   *   Cache tags.
   */
  private function buildCacheTags(NodeInterface $node, ?AiContentAssessment $assessment): array {
    $tags = $node->getCacheTags();
    $tags[] = 'ai_content_assessment_list';

    if ($assessment instanceof AiContentAssessment) {
      $tags = array_merge($tags, $assessment->getCacheTags());
    }

    return array_values(array_unique($tags));
  }

  /**
   * Normalizes machine keys and labels for matching.
   */
  private function normalizeKey(string $value): string {
    return strtolower(str_replace('-', '_', trim($value)));
  }

  /**
   * Normalizes a checkpoint status.
   */
  private function normalizeStatus(string $status): string {
    return match ($this->normalizeKey($status)) {
      'pass' => 'pass',
      'fail' => 'fail',
      'warning', 'warn' => 'warning',
      default => 'info',
    };
  }

  /**
   * Normalizes a checkpoint priority.
   */
  private function normalizePriority(string $priority): string {
    return match ($this->normalizeKey($priority)) {
      'high' => 'high',
      'medium' => 'medium',
      default => 'low',
    };
  }

  /**
   * Returns translated dimension labels.
   */
  private function dimensionLabel(string $dimensionId): mixed {
    return match ($dimensionId) {
      'technical_seo' => $this->t('Technical SEO'),
      'content_quality' => $this->t('Content Quality'),
      'schema_markup' => $this->t('Schema Markup'),
      default => $dimensionId,
    };
  }

  /**
   * Returns translated status labels.
   */
  private function statusLabel(string $status): string {
    return (string) match ($status) {
      'pass' => $this->t('Pass'),
      'fail' => $this->t('Fail'),
      'warning' => $this->t('Warning'),
      default => $this->t('Info'),
    };
  }

  /**
   * Returns translated priority labels.
   */
  private function priorityLabel(string $priority): string {
    return (string) match ($priority) {
      'high' => $this->t('High'),
      'medium' => $this->t('Medium'),
      default => $this->t('Low'),
    };
  }

}
