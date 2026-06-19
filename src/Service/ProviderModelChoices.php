<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Wraps Drupal AI simple provider/model option helpers for chat models.
 */
class ProviderModelChoices {

  public function __construct(
    protected AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * Returns configured provider/model pairs for an operation type.
   *
   * @return array<int, array<string, string>>
   *   Choice rows keyed for AIRO UI usage.
   */
  public function forOperationType(string $op_type = 'chat'): array {
    $choices = [];
    foreach ($this->getFlatSelectOptions($op_type) as $key => $label) {
      [$provider_id, $model_id] = $this->parseKey((string) $key);
      if ($provider_id === '' || $model_id === '') {
        continue;
      }
      if (!$this->isUsableForOperationType($provider_id, $model_id, $op_type)) {
        continue;
      }

      $choices[] = [
        'key' => (string) $key,
        'label' => $this->renderModelLabel($label, $provider_id),
        'provider_label' => $provider_id,
        'provider_id' => $provider_id,
        'model_id' => $model_id,
      ];
    }
    return $choices;
  }

  /**
   * Returns Drupal AI simple provider/model options.
   *
   * @return array<string, mixed>
   *   Options keyed by Drupal AI's supported simple option value.
   */
  public function getSelectOptions(string $op_type = 'chat'): array {
    return $this->getFlatSelectOptions($op_type);
  }

  /**
   * Returns options suitable for a Form API select.
   *
   * Drupal AI may return either flat options or provider optgroups; both are
   * valid for Form API select elements.
   *
   * @return array<string, mixed>
   *   Options keyed by Drupal AI's supported simple option value, possibly
   *   grouped by provider label.
   */
  public function getGroupedSelectOptions(string $op_type = 'chat'): array {
    try {
      return $this->filterSelectOptions(
        $this->aiProvider->getSimpleProviderModelOptions(
          $op_type,
          FALSE,
          TRUE,
          $this->getCapabilities($op_type),
        ) ?: [],
        $op_type,
      );
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Parses a Drupal AI simple option into provider and model IDs.
   *
   * @return array{0: string, 1: string}
   *   Provider plugin ID and model ID. Empty strings mean the option is invalid
   *   or no longer available.
   */
  public function parseKey(string $key): array {
    if ($key === '') {
      return ['', ''];
    }

    try {
      $provider = $this->aiProvider->loadProviderFromSimpleOption($key);
      $model = (string) $this->aiProvider->getModelNameFromSimpleOption($key);
    }
    catch (\Throwable) {
      return ['', ''];
    }

    $provider_id = $this->getProviderId($provider, $key);
    if ($provider_id === '' || $model === '') {
      return ['', ''];
    }

    return [$provider_id, $model];
  }

  /**
   * Loads a provider/model pair from a Drupal AI simple option.
   *
   * @return array{0: object|null, 1: string}
   *   Provider plugin instance and model ID.
   */
  public function loadFromKey(string $key): array {
    if ($key === '') {
      return [NULL, ''];
    }

    try {
      $provider = $this->aiProvider->loadProviderFromSimpleOption($key);
      $model = (string) $this->aiProvider->getModelNameFromSimpleOption($key);
    }
    catch (\Throwable) {
      return [NULL, ''];
    }

    return [$provider, $model];
  }

  /**
   * Finds the Drupal AI simple option for a legacy provider/model pair.
   */
  public function findKeyForProviderModel(string $provider_id, string $model_id, string $op_type = 'chat'): string {
    if ($provider_id === '' || $model_id === '') {
      return '';
    }

    foreach (array_keys($this->getFlatSelectOptions($op_type)) as $key) {
      [$candidate_provider_id, $candidate_model_id] = $this->parseKey((string) $key);
      if ($candidate_provider_id === $provider_id && $candidate_model_id === $model_id) {
        return (string) $key;
      }
    }

    return '';
  }

  /**
   * Returns model capability filters for a Drupal AI operation type.
   *
   * AIRO sends a system message in both assessment and preview chat calls, so
   * prefer models that declare system-role support when providers expose that
   * metadata through Drupal AI.
   *
   * @return array<int, \Drupal\ai\Enum\AiModelCapability>
   *   Capability filters passed to Drupal AI provider/model helpers.
   */
  private function getCapabilities(string $op_type): array {
    return $op_type === 'chat' ? [AiModelCapability::ChatSystemRole] : [];
  }

  /**
   * Renders a label value from the AI module to a plain string.
   */
  private function renderLabel(mixed $label): string {
    if ($label instanceof TranslatableMarkup || $label instanceof FormattableMarkup) {
      return $label->render();
    }

    return (string) $label;
  }

  /**
   * Renders a simple model label without the provider prefix.
   */
  private function renderModelLabel(mixed $label, string $provider_id): string {
    $text = $this->renderLabel($label);
    $provider_prefix = preg_quote($provider_id, '/');
    $text = preg_replace('/^' . $provider_prefix . '\s+-\s+/i', '', $text) ?? $text;
    $text = preg_replace('/^[^-]+?\s+-\s+/', '', $text, 1) ?? $text;

    return preg_replace('/\bgpt\b/i', 'GPT', $text) ?? $text;
  }

  /**
   * Resolves a provider plugin ID from a simple option provider instance.
   */
  private function getProviderId(mixed $provider, string $key): string {
    if (is_object($provider) && method_exists($provider, 'getPluginId')) {
      return (string) $provider->getPluginId();
    }

    $parts = preg_split('/__|[:|;]/', $key, 2);
    return (string) ($parts[0] ?? '');
  }

  /**
   * Returns Drupal AI simple provider/model options as a flat array.
   *
   * @return array<string, mixed>
   *   Flat options keyed by Drupal AI's supported simple option value.
   */
  private function getFlatSelectOptions(string $op_type): array {
    $options = $this->getGroupedSelectOptions($op_type);
    $flat = [];

    foreach ($options as $key => $label) {
      if (is_array($label)) {
        foreach ($label as $nested_key => $nested_label) {
          $flat[(string) $nested_key] = $nested_label;
        }
        continue;
      }

      $flat[(string) $key] = $label;
    }

    return $flat;
  }

  /**
   * Filters select options to the models AIRO can safely call.
   *
   * Drupal AI's OpenAI provider currently treats every model ID beginning with
   * GPT as a chat model. That leaks image, audio, realtime, transcription and
   * search catalog entries into chat selectors. Keep that provider-specific
   * compatibility cleanup here, after the Drupal AI helper has produced the
   * supported simple option values.
   */
  private function filterSelectOptions(array $options, string $op_type): array {
    $filtered = [];
    foreach ($options as $key => $label) {
      if (is_array($label)) {
        $nested = $this->filterSelectOptions($label, $op_type);
        if ($nested !== []) {
          $filtered[$key] = $nested;
        }
        continue;
      }

      [$provider_id, $model_id] = $this->parseKey((string) $key);
      if ($provider_id !== '' && $model_id !== '' && $this->isUsableForOperationType($provider_id, $model_id, $op_type)) {
        $filtered[$key] = $this->renderModelLabel($label, $provider_id);
      }
    }

    return $filtered;
  }

  /**
   * Whether a resolved provider/model pair is usable for an operation type.
   */
  private function isUsableForOperationType(string $provider_id, string $model_id, string $op_type): bool {
    if ($op_type !== 'chat') {
      return TRUE;
    }

    if ($provider_id !== 'openai') {
      return TRUE;
    }

    $blocked_fragments = [
      'audio',
      'codex',
      'image',
      'instruct',
      'realtime',
      'search',
      'transcribe',
      'tts',
    ];
    $normalized = strtolower($model_id);
    foreach ($blocked_fragments as $fragment) {
      if (str_contains($normalized, $fragment)) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
