<?php

/**
 * @file
 * PHPUnit bootstrap for ai_content_audit (standalone or contrib install).
 *
 * Locates Drupal core from the module directory and enables BypassFinals so
 * unit tests can mock final Drupal AI classes (e.g. AiProviderPluginManager).
 */

declare(strict_types=1);

use DG\BypassFinals;

/**
 * Finds and loads the nearest Composer autoloader (for dg/bypass-finals).
 */
function ai_content_audit_bootstrap_autoload(string $start): void {
  $dir = $start;
  for ($i = 0; $i < 12; $i++) {
    $autoload = $dir . '/vendor/autoload.php';
    if (is_file($autoload)) {
      require_once $autoload;
      return;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
      break;
    }
    $dir = $parent;
  }
}

/**
 * Finds Drupal core's PHPUnit bootstrap relative to the module root.
 */
function ai_content_audit_find_core_bootstrap(string $start): string {
  $dir = $start;
  for ($i = 0; $i < 12; $i++) {
    foreach (['core/tests/bootstrap.php', 'web/core/tests/bootstrap.php', 'docroot/core/tests/bootstrap.php'] as $relative) {
      $candidate = $dir . '/' . $relative;
      if (is_file($candidate)) {
        return $candidate;
      }
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
      break;
    }
    $dir = $parent;
  }

  throw new \RuntimeException('Cannot locate Drupal core/tests/bootstrap.php from ' . $start);
}

$module_root = dirname(__DIR__);
ai_content_audit_bootstrap_autoload($module_root);

if (class_exists(BypassFinals::class)) {
  BypassFinals::enable();
}

require_once ai_content_audit_find_core_bootstrap($module_root);
