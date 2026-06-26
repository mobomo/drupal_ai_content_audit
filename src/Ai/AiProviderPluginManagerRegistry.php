<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Ai;

use Drupal\ai\AiProviderInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Plugin\ProviderProxy;

/**
 * Delegates AI provider registry calls to Drupal AI's plugin manager.
 */
final class AiProviderPluginManagerRegistry implements AiProviderRegistryInterface {

  public function __construct(
    private readonly AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function createInstance(string $plugin_id, array $configuration = []): ProviderProxy {
    return $this->aiProvider->createInstance($plugin_id, $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function hasProvidersForOperationType(string $operation_type, bool $setup = TRUE): bool {
    return $this->aiProvider->hasProvidersForOperationType($operation_type, $setup);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultProviderForOperationType(string $operation_type): ?array {
    return $this->aiProvider->getDefaultProviderForOperationType($operation_type);
  }

  /**
   * {@inheritdoc}
   */
  public function getSimpleProviderModelOptions(
    string $operation_type,
    bool $empty = TRUE,
    bool $setup = TRUE,
    array $capabilities = [],
  ): array {
    return $this->aiProvider->getSimpleProviderModelOptions(
      $operation_type,
      $empty,
      $setup,
      $capabilities,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function loadProviderFromSimpleOption(string $option): AiProviderInterface|ProviderProxy|null {
    return $this->aiProvider->loadProviderFromSimpleOption($option);
  }

  /**
   * {@inheritdoc}
   */
  public function getModelNameFromSimpleOption(string $option): string {
    return $this->aiProvider->getModelNameFromSimpleOption($option);
  }

}
