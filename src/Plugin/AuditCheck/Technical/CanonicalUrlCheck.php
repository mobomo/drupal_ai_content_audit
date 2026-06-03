<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Technical;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase;
use Drupal\ai_content_audit\Service\HtmlFetchService;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks published nodes have a canonical URL meta tag.
 */
#[AuditCheck(
  id: 'canonical_url',
  label: new TranslatableMarkup('Canonical URL'),
  description: new TranslatableMarkup('Checks that published nodes have a canonical URL meta tag pointing to the correct URL.'),
  scope: 'node',
  category: 'Technical',
)]
class CanonicalUrlCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly HtmlFetchService $htmlFetch,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai_content_audit.html_fetch'),
      $container->get('module_handler'),
      $container->get('logger.factory')->get('ai_content_audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    // Phase 1: Check module availability (baseline check).
    $hasMetatag = $this->moduleHandler->moduleExists('metatag');

    // Phase 2: Resolve expected URL.
    $canonicalFound = FALSE;
    $canonicalValue = NULL;
    $canonicalValid = FALSE;
    $httpCheckFailed = FALSE;
    $expectedUrl = NULL;

    try {
      if ($node !== NULL) {
        $expectedUrl = Url::fromRoute('entity.node.canonical', ['node' => $node->id()])
          ->setAbsolute()
          ->toString();
      }
      else {
        $expectedUrl = $this->htmlFetch->getBaseUrl() . '/';
      }
    }
    catch (\Exception $e) {
      $httpCheckFailed = TRUE;
      $this->logger->warning('Technical audit: canonical URL resolution failed: @msg', ['@msg' => $e->getMessage()]);
    }

    // Phase 3: Fetch page HTML via shared cache and inspect the canonical tag.
    if (!$httpCheckFailed && $expectedUrl !== NULL) {
      $html = $this->htmlFetch->fetchPageHtml($expectedUrl);

      if ($html === NULL) {
        $httpCheckFailed = TRUE;
        $this->logger->warning('Technical audit: canonical URL live check failed: could not fetch @url.', ['@url' => $expectedUrl]);
      }
      else {
        // Parse canonical link tag — handle both attribute orders.
        if (preg_match(
          '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\'][^>]*\/?>/i',
          $html,
          $canonicalMatch
        ) || preg_match(
          '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\'][^>]*\/?>/i',
          $html,
          $canonicalMatch
        )) {
          $canonicalFound = TRUE;
          $canonicalValue = $canonicalMatch[1];
          // Validate the canonical points back to the expected URL.
          $canonicalValid = (rtrim($canonicalValue, '/') === rtrim($expectedUrl, '/'));
        }
      }
    }

    // Determine final status and description.
    $details = [
      'module_installed' => $hasMetatag,
      'canonical_found' => $canonicalFound,
      'canonical_url' => $canonicalValue,
      'expected_url' => $expectedUrl,
      'canonical_valid' => $canonicalValid,
      'http_check_failed' => $httpCheckFailed,
    ];

    if ($httpCheckFailed) {
      $description = 'Could not perform live canonical check. '
        . ($hasMetatag ? 'Metatag module is installed.' : 'Metatag module is not installed.');
      if ($hasMetatag) {
        return $this->warning($description, $canonicalValue, NULL, $details);
      }
      return $this->fail($description, $canonicalValue, NULL, $details);
    }

    if ($canonicalFound && $canonicalValid) {
      return $this->pass(
        'Canonical tag is present and points to the correct URL: ' . $canonicalValue,
        $canonicalValue,
        NULL,
        $details,
      );
    }

    if ($canonicalFound && !$canonicalValid) {
      return $this->warning(
        'Canonical tag found but points to an unexpected URL: ' . $canonicalValue
          . '. Expected: ' . $expectedUrl,
        $canonicalValue,
        NULL,
        $details,
      );
    }

    // No canonical found.
    $description = $hasMetatag
      ? 'Metatag module is installed but no <link rel="canonical"> found on the checked page. Verify per-content-type metatag configuration.'
      : 'No <link rel="canonical"> found and Metatag module is not installed. Canonical URLs help AI systems identify the authoritative version of each page.';

    if ($hasMetatag) {
      return $this->warning($description, $canonicalValue, NULL, $details);
    }

    return $this->fail($description, $canonicalValue, NULL, $details);
  }

}
