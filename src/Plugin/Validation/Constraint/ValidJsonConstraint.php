<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates that a field value contains valid JSON.
 */
#[Constraint(
  id: 'ValidJson',
  label: new TranslatableMarkup('Valid JSON', [], ['context' => 'Validation']),
)]
class ValidJsonConstraint extends SymfonyConstraint {

  /**
   * The error message for invalid JSON.
   */
  public string $message = 'The value is not valid JSON: @error';

  /**
   * Whether empty values are allowed.
   *
   * When TRUE (the default), NULL and empty-string values skip validation
   * and are treated as passing. Set to FALSE to require non-empty JSON.
   */
  public bool $allowEmpty = TRUE;

}
