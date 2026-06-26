<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Ai;

use Drupal\ai\AiProviderInterface;
use Drupal\ai\Plugin\ProviderProxy;

/**
 * Testable facade over Drupal AI provider discovery helpers.
 */
interface AiProviderRegistryInterface {

  /**
   * Creates a provider plugin proxy instance.
   */
  public function createInstance(string $plugin_id, array $configuration = []): ProviderProxy;

  /**
   * Whether any provider supports an operation type.
   */
  public function hasProvidersForOperationType(string $operation_type, bool $setup = TRUE): bool;

  /**
   * Returns the default provider/model pair for an operation type.
   *
   * @return array<string, mixed>|null
   *   Provider defaults or NULL when unset.
   */
  public function getDefaultProviderForOperationType(string $operation_type): ?array;

  /**
   * Returns Drupal AI simple provider/model options.
   *
   * @param array<int, \Drupal\ai\Enum\AiModelCapability> $capabilities
   *   Optional model capability filters.
   *
   * @return array<string, mixed>
   *   Simple option values keyed for Form API usage.
   */
  public function getSimpleProviderModelOptions(
    string $operation_type,
    bool $empty = TRUE,
    bool $setup = TRUE,
    array $capabilities = [],
  ): array;

  /**
   * Loads the provider plugin for a simple option value.
   */
  public function loadProviderFromSimpleOption(string $option): AiProviderInterface|ProviderProxy|null;

  /**
   * Extracts the model ID from a simple option value.
   */
  public function getModelNameFromSimpleOption(string $option): string;

}
