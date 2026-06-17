<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit;

use Drupal\ai_content_audit\Form\AiContentAuditPromptSubform;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Alters services provided by dependencies.
 */
final class AiContentAuditServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('ai.prompt_subform')) {
      $container
        ->getDefinition('ai.prompt_subform')
        ->setClass(AiContentAuditPromptSubform::class);
    }
  }

}
