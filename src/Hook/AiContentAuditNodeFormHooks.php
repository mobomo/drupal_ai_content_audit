<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\ai_content_audit\Service\NodeEditFormAlterer;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Node form hooks for AIRO sidebar.
 */
final class AiContentAuditNodeFormHooks {

  public function __construct(
    protected NodeEditFormAlterer $nodeEditFormAlterer,
  ) {}

  /**
   * Implements hook_form_node_form_alter().
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $this->nodeEditFormAlterer->alterForm($form, $form_state, $form_id);
  }

}
