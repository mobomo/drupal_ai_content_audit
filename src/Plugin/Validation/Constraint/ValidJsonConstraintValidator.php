<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ValidJson constraint.
 */
class ValidJsonConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    /** @var \Drupal\ai_content_audit\Plugin\Validation\Constraint\ValidJsonConstraint $constraint */

    // Resolve the raw scalar from a typed-data item or FieldItemList.
    $raw = $value;
    if (is_object($value) && method_exists($value, 'value')) {
      $raw = $value->value;
    }

    // Allow empty/NULL values when the option permits it (default: TRUE).
    if ($constraint->allowEmpty && (is_null($raw) || $raw === '')) {
      return;
    }

    // Non-empty check outside allowEmpty mode.
    if (is_null($raw) || $raw === '') {
      return;
    }

    json_decode($raw);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->context->addViolation($constraint->message, [
        '@error' => json_last_error_msg(),
      ]);
    }
  }

}
