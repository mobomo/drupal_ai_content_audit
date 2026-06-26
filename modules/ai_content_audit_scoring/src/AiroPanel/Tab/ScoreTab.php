<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\AiroPanel\Tab;

use Drupal\ai_content_audit\AiroPanel\AiroPanelTabInterface;
use Drupal\ai_content_audit_scoring\Repository\AiContentAssessmentRepository;
use Drupal\ai_content_audit_scoring\Service\AiroPanelTabBuilder;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Provides the AIRO score tab.
 */
final class ScoreTab implements AiroPanelTabInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly AiroPanelTabBuilder $tabBuilder,
    private readonly AiContentAssessmentRepository $assessmentRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'score-tab';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return $this->t('AI Score');
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return 10;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node, bool $pageSkin = FALSE): bool {
    return !$pageSkin;
  }

  /**
   * {@inheritdoc}
   */
  public function build(NodeInterface $node, bool $pageSkin = FALSE): array {
    $assessment = $this->assessmentRepository->getLatestForNode((int) $node->id());
    return $this->tabBuilder->buildScoreTab($node, $assessment);
  }

}
