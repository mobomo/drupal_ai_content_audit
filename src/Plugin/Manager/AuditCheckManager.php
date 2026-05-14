<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\Manager;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckInterface;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\node\NodeInterface;

/**
 * Plugin manager for AuditCheck plugins.
 *
 * Discovers plugins using the PHP 8 #[AuditCheck] attribute from any enabled
 * module's Plugin/AuditCheck/ sub-namespace.
 *
 * Other modules can alter discovered definitions via:
 * @code
 *   function mymodule_audit_check_info_alter(array &$definitions): void {
 *     // Modify $definitions keyed by plugin ID.
 *   }
 * @endcode
 *
 * Usage:
 * @code
 *   $results = $this->auditCheckManager->runAll($node);
 * @endcode
 *
 * @see \Drupal\ai_content_audit\Attribute\AuditCheck
 * @see \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckInterface
 * @see \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase
 * @see \Drupal\Core\Plugin\DefaultPluginManager
 */
class AuditCheckManager extends DefaultPluginManager {

  /**
   * Constructs an AuditCheckManager.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use for plugin discovery caching.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to read disabled check IDs from settings.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct(
      'Plugin/AuditCheck',
      $namespaces,
      $module_handler,
      AuditCheckInterface::class,
      AuditCheck::class,
    );

    // Enable hook_audit_check_info_alter().
    $this->alterInfo('audit_check_info');
    // Cache discovered plugin definitions under a stable key.
    $this->setCacheBackend($cache_backend, 'audit_check_plugins');
  }

  /**
   * Returns the IDs of all currently enabled audit checks.
   *
   * Reads the 'disabled_checks' array from the ai_content_audit.settings config
   * object and subtracts those IDs from the full set of discovered definitions.
   *
   * @return string[]
   *   Plugin IDs that are not disabled in configuration.
   */
  public function getEnabledCheckIds(): array {
    $disabledChecks = $this->configFactory
      ->get('ai_content_audit.settings')
      ->get('disabled_checks') ?? [];

    $disabledChecks = is_array($disabledChecks) ? $disabledChecks : [];
    $allIds = array_keys($this->getDefinitions());

    return array_values(array_diff($allIds, $disabledChecks));
  }

  /**
   * Runs all enabled audit checks and returns their results.
   *
   * For each enabled check, this method:
   *  1. Creates a plugin instance via createInstance().
   *  2. Calls applies() to confirm the check is relevant to the given context.
   *  3. Calls run() to execute the check.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   Optional node for node-scoped checks. Pass NULL to run site-scoped
   *   checks only.
   *
   * @return array<string, \Drupal\ai_content_audit\ValueObject\TechnicalAuditResult>
   *   Keyed array of TechnicalAuditResult objects indexed by check plugin ID.
   */
  public function runAll(?NodeInterface $node = NULL): array {
    $results = [];

    foreach ($this->getEnabledCheckIds() as $id) {
      try {
        /** @var \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckInterface $check */
        $check = $this->createInstance($id);

        if (!$check->applies($node)) {
          continue;
        }

        $results[$id] = $check->run($node);
      }
      catch (\Exception $e) {
        // Surface a failure result rather than aborting the whole audit.
        $results[$id] = new TechnicalAuditResult(
          check: $id,
          label: $id,
          status: 'fail',
          currentContent: NULL,
          recommendedContent: NULL,
          description: sprintf('Check "%s" threw an exception: %s', $id, $e->getMessage()),
        );
      }
    }

    return $results;
  }

}
