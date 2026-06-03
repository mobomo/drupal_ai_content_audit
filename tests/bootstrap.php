<?php

/**
 * @file
 * PHPUnit bootstrap for ai_content_audit unit tests.
 *
 * Registers the module's PSR-4 namespace and tests namespace on top of
 * Drupal core's autoloader so unit tests can run without a full Drupal boot.
 */

// Load Drupal core's composer autoloader (provides Drupal\Core\*, GuzzleHttp,
// Symfony\Component\HttpFoundation, PSR interfaces, etc.).
// Path: tests/ → ai_content_audit/ → custom/ → modules/ → web/autoload.php.
$loader = require_once __DIR__ . '/../../../../autoload.php';

// Register this module's src/ directory under Drupal\ai_content_audit\.
$loader->addPsr4('Drupal\\ai_content_audit\\', __DIR__ . '/../src/');

// Register the test namespace as well.
$loader->addPsr4('Drupal\\Tests\\ai_content_audit\\', __DIR__ . '/src/');

// Register Drupal module namespaces used by unit tests.
// These modules are not registered by the composer autoloader — they are
// normally loaded by Drupal's own extension classloader. Adding them here
// makes interfaces available to PHPUnit's mock generator without a full boot.
$loader->addPsr4('Drupal\\node\\', __DIR__ . '/../../../../core/modules/node/src/');
$loader->addPsr4('Drupal\\user\\', __DIR__ . '/../../../../core/modules/user/src/');
