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
 * Checks that the homepage declares a specific lang attribute and lists hreflang alternates.
 */
#[AuditCheck(
  id: 'language_declaration',
  label: new TranslatableMarkup('Language Declaration'),
  description: new TranslatableMarkup('Checks that the homepage declares a specific lang attribute and lists hreflang alternates.'),
  scope: 'site',
  category: 'Technical',
)]
class LanguageDeclarationCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

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
      return $this->warning(
        'Could not fetch homepage HTML to check language declaration.',
        NULL,
        NULL,
        [
          'has_lang_attribute' => FALSE,
          'lang_value' => NULL,
          'hreflang_count' => 0,
          'hreflang_values' => [],
        ],
      );
    }

    // Parse <html lang="..."> attribute.
    $hasLang = FALSE;
    $langValue = NULL;
    if (preg_match('/<html[^>]*\slang=["\']([^"\']+)["\']/i', $html, $langMatch)) {
      $hasLang = TRUE;
      $langValue = $langMatch[1];
    }

    // Collect all hreflang values.
    $hreflangValues = [];
    preg_match_all(
      '/<link[^>]*rel=["\']alternate["\'][^>]*hreflang=["\']([^"\']+)["\']/i',
      $html,
      $hreflangMatches
    );
    if (!empty($hreflangMatches[1])) {
      $hreflangValues = $hreflangMatches[1];
    }
    // Also match the reverse attribute order: hreflang before rel.
    preg_match_all(
      '/<link[^>]*hreflang=["\']([^"\']+)["\'][^>]*rel=["\']alternate["\']/i',
      $html,
      $hreflangMatchesAlt
    );
    if (!empty($hreflangMatchesAlt[1])) {
      $hreflangValues = array_unique(array_merge($hreflangValues, $hreflangMatchesAlt[1]));
    }
    $hreflangValues = array_values($hreflangValues);

    $details = [
      'has_lang_attribute' => $hasLang,
      'lang_value' => $langValue,
      'hreflang_count' => count($hreflangValues),
      'hreflang_values' => $hreflangValues,
    ];

    // Determine status.
    $emptyLikeValues = ['', 'und', 'zxx'];
    if (!$hasLang) {
      return $this->fail(
        'No lang attribute found on <html> element. Language declaration helps LLMs classify content correctly.',
        $langValue,
        NULL,
        $details,
      );
    }

    if (in_array(strtolower((string) $langValue), $emptyLikeValues, TRUE)) {
      return $this->warning(
        'lang attribute is present but has a non-specific value "' . $langValue . '". Set a specific language code (e.g. "en", "fr").',
        $langValue,
        NULL,
        $details,
      );
    }

    return $this->pass(
      'Language declared as "' . $langValue . '" on <html> element.'
        . (count($hreflangValues) > 0 ? ' ' . count($hreflangValues) . ' hreflang tag(s) found.' : ''),
      $langValue,
      NULL,
      $details,
    );
  }

}
