<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Service;

use Drupal\ai_content_audit_scoring\Entity\AiContentAssessment;
use Drupal\ai_content_audit_scoring\Repository\AiContentAssessmentRepository;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Builds normalized data for the AIRO readiness checkpoints widget.
 */
final class AiroReadinessViewModelBuilder {

  use StringTranslationTrait;

  /**
   * Baseline dimensions expected from assessment prompts.
   */
  private const DIMENSIONS = [
    'technical seo' => [
      'max_score' => 40,
    ],
    'content quality' => [
      'max_score' => 35,
    ],
    'schema markup' => [
      'max_score' => 25,
    ],
  ];

  public function __construct(
    private readonly AiContentAssessmentRepository $assessmentRepository,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * Builds a template-ready widget model for a node.
   *
   * @return array{
   *   has_assessment: bool,
   *   node_id: int,
   *   revision_id: int,
   *   items: array<int, array<string, mixed>>,
   *   action_label: string,
   *   assess_url: string,
   *   refresh_url: string,
   *   full_breakdown_enabled: bool,
   *   last_check_label: string,
   *   cache_tags: string[],
   *   cache_contexts: string[]
   *   }
   *   Normalized widget data.
   */
  public function build(NodeInterface $node): array {
    $assessment = $this->assessmentRepository->getLatestForNode((int) $node->id());
    $hasAssessment = $assessment instanceof AiContentAssessment;

    return [
      'has_assessment' => $hasAssessment,
      'node_id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'items' => $this->buildItems($assessment),
      'action_label' => (string) ($hasAssessment ? $this->t('Re-Analyze') : $this->t('Run Analysis')),
      'assess_url' => $this->buildAssessUrl($node),
      'refresh_url' => $this->buildRefreshUrl($node),
      'full_breakdown_enabled' => FALSE,
      'last_check_label' => $this->buildLastCheckLabel($assessment),
      'cache_tags' => $this->buildCacheTags($node, $assessment),
      'cache_contexts' => ['route', 'user.permissions'],
    ];
  }

  /**
   * Builds normalized score rows.
   *
   * @return array<int, array<string, mixed>>
   *   Score rows keyed numerically in display order.
   */
  private function buildItems(?AiContentAssessment $assessment): array {
    $items = [];
    $subScoresByKey = [];

    if ($assessment instanceof AiContentAssessment) {
      foreach ($assessment->getSubScores() ?? [] as $subScore) {
        if (!is_array($subScore)) {
          continue;
        }
        $label = (string) ($subScore['label'] ?? '');
        if ($label === '') {
          continue;
        }
        $subScoresByKey[$this->normalizeKey($label)] = $subScore;
      }
    }

    foreach (self::DIMENSIONS as $key => $definition) {
      $subScore = $subScoresByKey[$key] ?? NULL;
      $maxScore = (int) ($subScore['max_score'] ?? $definition['max_score']);
      $score = isset($subScore['score']) ? (int) $subScore['score'] : NULL;

      $items[] = [
        'label' => $this->dimensionLabel($key),
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
   * Builds the assessment endpoint URL.
   */
  private function buildAssessUrl(NodeInterface $node): string {
    try {
      return $this->urlGenerator->generateFromRoute(
        'ai_content_audit.panel.assess',
        ['node' => $node->id()],
      );
    }
    catch (RouteNotFoundException) {
      return '';
    }
  }

  /**
   * Builds the widget refresh endpoint URL.
   */
  private function buildRefreshUrl(NodeInterface $node): string {
    try {
      return $this->urlGenerator->generateFromRoute(
        'ai_content_audit_scoring.readiness.refresh',
        ['node' => $node->id()],
      );
    }
    catch (RouteNotFoundException) {
      return '';
    }
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
   * Builds cache tags for the widget.
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
   * Normalizes labels for matching assessment data to known dimensions.
   */
  private function normalizeKey(string $label): string {
    return strtolower(trim($label));
  }

  /**
   * Returns the translated display label for a known dimension.
   */
  private function dimensionLabel(string $key): mixed {
    return match ($key) {
      'technical seo' => $this->t('Technical SEO'),
      'content quality' => $this->t('Content Quality'),
      'schema markup' => $this->t('Schema Markup'),
      default => $key,
    };
  }

}
