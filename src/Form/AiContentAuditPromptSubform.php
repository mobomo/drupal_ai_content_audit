<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Form;

use Drupal\ai\Entity\AiPromptTypeInterface;
use Drupal\ai\Form\AiPromptSubform;
use Drupal\Core\Form\FormStateInterface;

/**
 * Makes inline AI Prompt validation safe for multiple prompt widgets.
 */
final class AiContentAuditPromptSubform extends AiPromptSubform {

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, array $values, FormStateInterface $form_state): void {
    $base_name = '';
    if (!empty($form['#parents'])) {
      $base_name .= implode('][', $form['#parents']) . '][';
    }

    if (empty($values['label']) && empty($values['id'])) {
      return;
    }

    $prompt_type = $this->loadPromptTypeFromForm($form);
    $prompt_type_id = $prompt_type?->id() ?? '';

    if (empty($values['label'])) {
      $form_state->setErrorByName($base_name . 'label', $this->t('Label is required.'));
    }
    if (empty($values['id'])) {
      $form_state->setErrorByName($base_name . 'id', $this->t('Machine name is required.'));
    }
    elseif ($prompt_type_id !== '' && $this->promptExists($prompt_type_id, (string) $values['id'])) {
      $form_state->setErrorByName($base_name . 'id', $this->t('The machine-readable name is already in use. It must be unique.'));
    }

    if (empty($values['prompt'])) {
      $form_state->setErrorByName($base_name . 'prompt', $this->t('Please enter a prompt text.'));
    }

    if (!$prompt_type instanceof AiPromptTypeInterface || empty($values['prompt'])) {
      return;
    }

    foreach ($prompt_type->getVariables() as $variable) {
      $full_name = '{' . $variable['name'] . '}';
      if ($variable['required'] && !str_contains((string) $values['prompt'], $full_name)) {
        $form_state->setErrorByName($base_name . 'prompt', $this->t('The prompt text must contain "@variable".', [
          '@variable' => $full_name,
        ]));
      }
    }

    foreach ($prompt_type->getTokens() as $token) {
      $full_name = '{' . $token['name'] . '}';
      if ($token['required'] && !str_contains((string) $values['prompt'], $full_name)) {
        $form_state->setErrorByName($base_name . 'prompt', $this->t('The prompt text must contain "@token".', [
          '@token' => $full_name,
        ]));
      }
    }
  }

  /**
   * Loads the prompt type attached to the concrete inline subform.
   */
  private function loadPromptTypeFromForm(array $form): ?AiPromptTypeInterface {
    $prompt_type_id = (string) ($form['id']['#attributes']['data-prompt-type'] ?? '');
    if ($prompt_type_id === '') {
      return NULL;
    }

    $prompt_type = $this->entityTypeManager
      ->getStorage('ai_prompt_type')
      ->load($prompt_type_id);

    return $prompt_type instanceof AiPromptTypeInterface ? $prompt_type : NULL;
  }

  /**
   * Checks whether an inline prompt machine name already exists.
   */
  private function promptExists(string $prompt_type_id, string $id): bool {
    return (bool) $this->entityTypeManager
      ->getStorage('ai_prompt')
      ->load($prompt_type_id . '__' . $id);
  }

}
