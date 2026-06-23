<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\ai_content_audit\Service\AiContentAuditLifecycle;
use Drupal\Core\Hook\Attribute\Hook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cron hook for assessment retention purge queue.
 */
final class AiContentAuditCronHooks {

  public function __construct(
    #[Autowire(service: 'ai_content_audit.lifecycle')]
    protected AiContentAuditLifecycle $lifecycle,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->lifecycle->enqueueExcessAssessmentsForPurge();
  }

}
