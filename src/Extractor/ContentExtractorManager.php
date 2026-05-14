<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Extractor;

use Drupal\ai_content_audit\Enum\RenderMode;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Plugin manager for ContentExtractor plugins.
 *
 * @see \Drupal\ai_content_audit\Annotation\ContentExtractor
 * @see hook_content_extractor_info_alter()
 */
class ContentExtractorManager extends DefaultPluginManager {

  /**
   * Constructs a ContentExtractorManager.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use for plugin discovery caching.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/ContentExtractor',
      $namespaces,
      $module_handler,
      ContentExtractorInterface::class,
      'Drupal\ai_content_audit\Annotation\ContentExtractor',
    );

    // Enable hook_content_extractor_info_alter().
    $this->alterInfo('content_extractor_info');
    // Cache discovered definitions under a stable key.
    $this->setCacheBackend($cache_backend, 'content_extractor_plugins');
  }

  /**
   * Returns an instantiated extractor for the given render mode.
   *
   * @param string $mode
   *   A RenderMode enum value string. Defaults to RenderMode::TEXT.
   *
   * @return \Drupal\ai_content_audit\Extractor\ContentExtractorInterface
   *   The matching extractor plugin instance.
   *
   * @throws \InvalidArgumentException
   *   If no plugin is registered for the given render mode.
   */
  public function getExtractorForMode(string $mode = ''): ContentExtractorInterface {
    if (empty($mode)) {
      $mode = RenderMode::default()->value;
    }

    foreach ($this->getDefinitions() as $plugin_id => $definition) {
      if (($definition['render_mode'] ?? '') === $mode) {
        return $this->createInstance($plugin_id);
      }
    }

    $available = implode(', ', $this->getAvailableModes());
    throw new \InvalidArgumentException(
      sprintf(
        'No content extractor plugin registered for render mode "%s". Available modes: %s.',
        $mode,
        $available ?: 'none'
      )
    );
  }

  /**
   * Returns all registered render mode strings.
   *
   * @return string[]
   *   Array of render mode strings from plugin definitions (e.g., ['text']).
   */
  public function getAvailableModes(): array {
    return array_column($this->getDefinitions(), 'render_mode');
  }

  /**
   * Returns whether a plugin is registered for the given render mode.
   *
   * @param string $mode
   *   A RenderMode enum value string.
   *
   * @return bool
   *   TRUE if a plugin handles the given mode.
   */
  public function hasExtractorForMode(string $mode): bool {
    foreach ($this->getDefinitions() as $definition) {
      if (($definition['render_mode'] ?? '') === $mode) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
