<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\ai_content_audit\Service\AiContentAuditLifecycle;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Cron hook for assessment retention purge queue.
 */
final class AiContentAuditCronHooks {

  public function __construct(
    protected AiContentAuditLifecycle $lifecycle,
  ) {}

  #[Hook('cron')]
  public function cron(): void {
    $this->lifecycle->enqueueExcessAssessmentsForPurge();
  }

}
