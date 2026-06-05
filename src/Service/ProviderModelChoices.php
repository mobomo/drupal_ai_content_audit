<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Enumerates all configured provider+model pairs for a given operation type.
 *
 * Uses each provider's getConfiguredModels() for short model labels and the
 * plugin definition label for provider names. Key format:
 * <provider_id>__<model_id> (double-underscore separator).
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
   *   - label (string): short model label from the provider (e.g. "GPT-4o").
   *   - provider_label (string): human-readable provider name (e.g. "OpenAI").
   *   - provider_id (string): provider plugin ID.
   *   - model_id (string): model identifier.
   */
  public function forOperationType(string $op_type = 'chat'): array {
    try {
      $providers = $this->aiProvider->getProvidersForOperationType($op_type, TRUE);
    }
    catch (\Exception) {
      return [];
    }

    $choices = [];
    foreach ($providers as $provider_id => $definition) {
      try {
        $provider = $this->aiProvider->createInstance($provider_id);
        $models = $provider->getConfiguredModels($op_type);
      }
      catch (\Exception) {
        continue;
      }

      $provider_label = $this->renderLabel($definition['label'] ?? $provider_id);
      foreach ($models as $model_id => $model_name) {
        if ($op_type === 'chat' && $this->isNonChatModelId((string) $model_id)) {
          continue;
        }

        $choices[] = [
          'key'            => $provider_id . '__' . $model_id,
          'label'          => $this->formatModelLabel(
            $this->renderLabel($model_name),
            (string) $model_id,
          ),
          'provider_label' => $provider_label,
          'provider_id'    => $provider_id,
          'model_id'       => (string) $model_id,
        ];
      }
    }

    return $choices;
  }

  /**
   * Returns a flat options array suitable for a Form API #type => 'select'.
   *
   * Keys are '<provider_id>__<model_id>'; values are model labels.
   * An empty-option entry is NOT prepended — add your own '- Select -' entry
   * in the form if desired.
   *
   * @param string $op_type
   *   The AI operation type to filter by. Defaults to 'chat'.
   *
   * @return array<string, string>
   *   Flat options array keyed by provider__model.
   */
  public function getSelectOptions(string $op_type = 'chat'): array {
    $options = [];
    foreach ($this->forOperationType($op_type) as $choice) {
      $options[$choice['key']] = $choice['label'];
    }
    return $options;
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
      $provider_label = $choice['provider_label'] ?? $choice['provider_id'];
      $grouped[$provider_label][$choice['key']] = $choice['label'];
    }
    return $grouped;
  }

  /**
   * Renders a label value from the AI module to a plain string.
   *
   * @param mixed $label
   *   Label value from a provider plugin or model list.
   *
   * @return string
   *   Plain-text label.
   */
  private function renderLabel(mixed $label): string {
    if ($label instanceof TranslatableMarkup || $label instanceof FormattableMarkup) {
      return $label->render();
    }

    return (string) $label;
  }

  /**
   * Whether a model ID should be hidden from content-audit chat pickers.
   *
   * Providers may expose embeddings, audio, image, moderation, or legacy
   * completion models alongside chat models (OpenAI "text-*" IDs are common).
   *
   * @param string $model_id
   *   Model identifier.
   *
   * @return bool
   *   TRUE when the model is not suitable for page content Q&A.
   */
  private function isNonChatModelId(string $model_id): bool {
    static $patterns = [
      // Moderation, speech, image gen, TTS.
      '/moderation/i',
      '/whisper/i',
      '/dall-e|dalle/i',
      '/^tts|tts-/i',
      '/clip/i',
      '/gpt-image/i',
      '/sora/i',
      // Embeddings and similarity/search helpers.
      '/embedding/i',
      '/similarity/i',
      '/search/i',
      // Legacy completion / edit / instruct APIs.
      '/instruct/i',
      '/\-edit-/i',
      '/text-(ada|babbage|curie|davinci)/i',
      // Realtime / audio pipelines.
      '/realtime/i',
      '/audio/i',
      '/transcribe/i',
      // Code-only models.
      '/codex/i',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $model_id)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Normalizes model labels for display (e.g. "gpt-4o" → "GPT-4o").
   *
   * @param string $label
   *   Human-readable model label from the provider.
   * @param string $model_id
   *   Model identifier used when the label is empty.
   *
   * @return string
   *   Display label with GPT in uppercase.
   */
  private function formatModelLabel(string $label, string $model_id): string {
    $text = $label !== '' ? $label : $model_id;
    return preg_replace('/\bgpt\b/i', 'GPT', $text) ?? $text;
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
