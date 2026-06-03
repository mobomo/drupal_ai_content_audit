<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai\AiProviderPluginManager;

/**
 * Enumerates all configured provider+model pairs for a given operation type.
 *
 * Wraps AiProviderPluginManager::getSimpleProviderModelOptions() to return a
 * normalised list of ['key', 'label', 'provider_id', 'model_id'] arrays that
 * the AIRO Preview tab can render as checkboxes and post back to the server.
 *
 * Key format: <provider_id>__<model_id>  (double-underscore separator).
 */
class ProviderModelChoices {

  public function __construct(
    protected AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * Returns all configured provider+model pairs for an operation type.
   *
   * @param string $op_type
   *   The AI operation type to filter by.  Defaults to 'chat'.
   *
   * @return array
   *   An indexed array of associative arrays, each containing:
   *   - key (string): composite '<provider_id>__<model_id>' key.
   *   - label (string): human-readable "Provider — Model" label.
   *   - provider_id (string): provider plugin ID.
   *   - model_id (string): model identifier.
   */
  public function forOperationType(string $op_type = 'chat'): array {
    try {
      // Returns ['openai__gpt-4o-mini' => 'OpenAI — GPT-4o mini', ...].
      $raw = $this->aiProvider->getSimpleProviderModelOptions($op_type, FALSE, TRUE);
    }
    catch (\Exception $e) {
      return [];
    }

    $choices = [];
    foreach ($raw as $key => $label) {
      [$provider_id, $model_id] = $this->parseKey((string) $key);
      if (empty($provider_id)) {
        continue;
      }
      $choices[] = [
        'key'         => (string) $key,
        'label'       => (string) $label,
        'provider_id' => $provider_id,
        'model_id'    => $model_id,
      ];
    }

    return $choices;
  }

  /**
   * Returns a flat options array suitable for a Form API #type => 'select'.
   *
   * Keys are '<provider_id>__<model_id>'; values are human-readable labels.
   * An empty-option entry is NOT prepended — add your own '- Select -' entry
   * in the form if desired.
   *
   * @param string $op_type
   *   The AI operation type to filter by. Defaults to 'chat'.
   *
   * @return array<string, string>
   *   Flat options array, e.g.:
   *   ['openai__gpt-4o' => 'OpenAI - GPT-4o',
   *    'anthropic__claude-3-5-sonnet-20241022' =>
   *      'Anthropic - Claude 3.5 Sonnet'].
   */
  public function getSelectOptions(string $op_type = 'chat'): array {
    try {
      // FALSE = no empty option; TRUE = require provider to be set up.
      return $this->aiProvider->getSimpleProviderModelOptions($op_type, FALSE, TRUE);
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Returns an optgroup-style options array keyed by provider label.
   *
   * Suitable for a Form API #type => 'select' with #options containing nested
   * arrays (Drupal renders these as <optgroup> elements).
   *
   * @param string $op_type
   *   The AI operation type to filter by. Defaults to 'chat'.
   *
   * @return array<string, array<string, string>>
   *   Grouped options, e.g.:
   *   ['OpenAI' => ['openai__gpt-4o' => 'GPT-4o', ...],
   *    'Anthropic' => ['anthropic__claude-3-5-sonnet' => 'Claude 3.5 ...']].
   */
  public function getGroupedSelectOptions(string $op_type = 'chat'): array {
    $choices = $this->forOperationType($op_type);
    $grouped = [];
    foreach ($choices as $choice) {
      // Derive a provider-only label by stripping the " — <model>" suffix when
      // the label is in the standard "Provider - Model" format.
      $provider_label = $choice['provider_id'];
      if (str_contains((string) $choice['label'], ' - ')) {
        $provider_label = explode(' - ', (string) $choice['label'], 2)[0];
      }
      $grouped[$provider_label][$choice['key']] = $choice['label'];
    }
    return $grouped;
  }

  /**
   * Parses a composite provider__model key into its components.
   *
   * @param string $key
   *   A key of the form '<provider_id>__<model_id>',
   *   e.g. 'openai__gpt-4o-mini'.
   *
   * @return array
   *   A two-element array: [$provider_id, $model_id].  Either element may be
   *   an empty string if the key is malformed.
   */
  public function parseKey(string $key): array {
    $parts = explode('__', $key, 2);
    return [$parts[0] ?? '', $parts[1] ?? ''];
  }

}
