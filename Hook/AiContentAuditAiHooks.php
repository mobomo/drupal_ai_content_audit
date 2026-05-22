<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Integration with the AI module operation types UI.
 */
final class AiContentAuditAiHooks {

  #[Hook('ai_operation_types_alter')]
  public function aiOperationTypesAlter(array &$operation_types): void {
    $operation_types['content_audit'] = [
      'id' => 'content_audit',
      'label' => 'Content Audit',
      'actual_type' => 'chat',
      'filter' => [],
    ];
  }

}
