<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Technical;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase;
use Drupal\ai_content_audit\Service\HtmlFetchService;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks for license links, JSON-LD license properties, and noai/noimageai robots directives.
 */
#[AuditCheck(
  id: 'content_licensing',
  label: new TranslatableMarkup('Content Licensing'),
  description: new TranslatableMarkup('Checks for license links, JSON-LD license properties, and noai/noimageai robots directives.'),
  scope: 'site',
  category: 'AI Signals',
)]
class ContentLicensingCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly HtmlFetchService $htmlFetch,
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
      $container->get('logger.factory')->get('ai_content_audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $homepageUrl = $this->htmlFetch->getBaseUrl() . '/';
    $html = $this->htmlFetch->fetchPageHtml($homepageUrl);

    if ($html === NULL) {
      return $this->info(
        'Could not fetch homepage HTML to check licensing signals.',
        NULL,
        NULL,
        [
          'has_license_link' => FALSE,
          'license_url' => NULL,
          'has_schema_license' => FALSE,
          'has_noai_directive' => FALSE,
          'has_noimageai_directive' => FALSE,
        ],
      );
    }

    // Check for <link rel="license" href="...">.
    $hasLicenseLink = FALSE;
    $licenseUrl = NULL;
    if (preg_match(
      '/<link[^>]+rel=["\']license["\'][^>]+href=["\']([^"\']+)["\']/i',
      $html,
      $licenseMatch
    ) || preg_match(
      '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']license["\']/i',
      $html,
      $licenseMatch
    )) {
      $hasLicenseLink = TRUE;
      $licenseUrl = $licenseMatch[1];
    }

    // Check JSON-LD blocks for a "license" property.
    $hasSchemaLicense = FALSE;
    preg_match_all(
      '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
      $html,
      $ldMatches
    );
    foreach ($ldMatches[1] ?? [] as $jsonText) {
      $data = json_decode(trim($jsonText), TRUE);
      if (!is_array($data)) {
        continue;
      }
      // Check flat objects and @graph arrays.
      $items = [];
      if (isset($data['@graph']) && is_array($data['@graph'])) {
        $items = $data['@graph'];
      }
      else {
        $items = [$data];
      }
      foreach ($items as $item) {
        if (is_array($item) && array_key_exists('license', $item)) {
          $hasSchemaLicense = TRUE;
          break 2;
        }
      }
    }

    // Check for noai / noimageai robots meta directives (emerging standard).
    $hasNoaiDirective = (bool) preg_match(
      '/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noai[^"\']*["\']/i',
      $html
    );
    $hasNoimageaiDirective = (bool) preg_match(
      '/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noimageai[^"\']*["\']/i',
      $html
    );

    $hasAnySignal = $hasLicenseLink || $hasSchemaLicense || $hasNoaiDirective || $hasNoimageaiDirective;

    $details = [
      'has_license_link' => $hasLicenseLink,
      'license_url' => $licenseUrl,
      'has_schema_license' => $hasSchemaLicense,
      'has_noai_directive' => $hasNoaiDirective,
      'has_noimageai_directive' => $hasNoimageaiDirective,
    ];

    if ($hasAnySignal) {
      $signals = [];
      if ($hasLicenseLink) {
        $signals[] = 'license link tag';
      }
      if ($hasSchemaLicense) {
        $signals[] = 'JSON-LD license property';
      }
      if ($hasNoaiDirective) {
        $signals[] = 'noai robots directive';
      }
      if ($hasNoimageaiDirective) {
        $signals[] = 'noimageai robots directive';
      }
      return $this->pass(
        'Content licensing signals found: ' . implode(', ', $signals) . '.',
        $licenseUrl,
        NULL,
        $details,
      );
    }

    return $this->info(
      'No content licensing signals detected. Adding a <link rel="license"> tag or JSON-LD license property helps AI systems understand content usage rights.',
      $licenseUrl,
      NULL,
      $details,
    );
  }

}
