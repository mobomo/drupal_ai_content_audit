<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Builds score tier metadata (CSS class, hex color, qualitative label).
 */
final class ScoreMetaBuilder {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return array{color: string, color_hex: string, label: \Drupal\Core\StringTranslation\TranslatableMarkup}
   */
  public function build(int $score): array {
    return match (TRUE) {
      $score < 50 => [
        'color' => 'danger',
        'color_hex' => '#ef4444',
        'label' => $this->t('Needs Work'),
      ],
      $score < 75 => [
        'color' => 'warning',
        'color_hex' => '#f59e0b',
        'label' => $this->t('Improving'),
      ],
      default => [
        'color' => 'good',
        'color_hex' => '#10b981',
        'label' => $this->t('AI Ready'),
      ],
    };
  }

}
