<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Trait;

/**
 * Provides shared schema.org JSON-LD parsing helpers.
 *
 * This trait contains the HTML-scanning helpers that were originally
 * implemented as protected methods on TechnicalAuditService. It is intended
 * for use by AuditCheck plugins that inspect structured data on pages
 * (e.g. SchemaMarkupCheck, DateMetaTagsCheck).
 *
 * Using classes gain:
 * - extractSchemaTypes()
 * - extractSchemaDateProperties()
 * - collectSchemaType()
 * - countJsonLdScripts()
 *
 * @see \Drupal\ai_content_audit\Service\TechnicalAuditService
 */
trait SchemaMarkupParserTrait {

  /**
   * Extracts all @type values from JSON-LD script blocks in HTML.
   *
   * Handles both flat objects ({ "@type": "Article" }) and @graph arrays
   * ({ "@graph": [{ "@type": "WebPage" }, ...] }).
   *
   * @param string $html
   *   The full HTML source to scan.
   *
   * @return string[]
   *   Deduplicated list of @type values found across all JSON-LD blocks.
   */
  protected function extractSchemaTypes(string $html): array {
    preg_match_all(
      '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
      $html,
      $matches
    );

    $types = [];
    foreach ($matches[1] ?? [] as $jsonText) {
      $data = json_decode(trim($jsonText), TRUE);
      if (!is_array($data)) {
        continue;
      }
      // Collect types from a @graph array.
      if (isset($data['@graph']) && is_array($data['@graph'])) {
        foreach ($data['@graph'] as $item) {
          $this->collectSchemaType($item, $types);
        }
      }
      else {
        $this->collectSchemaType($data, $types);
      }
    }

    return array_values(array_unique($types));
  }

  /**
   * Extracts article date properties from JSON-LD structured data in HTML.
   *
   * Inspects any JSON-LD objects whose @type is Article, NewsArticle, or
   * BlogPosting and checks for the presence and ISO 8601 format validity of
   * datePublished and dateModified properties.
   *
   * This method does NOT affect the pass/fail status of the schema check; its
   * results are provided as informational details for downstream consumers.
   *
   * @param string $html
   *   The full HTML source to scan.
   *
   * @return array{
   *   article_has_date_published: bool,
   *   article_has_date_modified: bool,
   *   date_published_valid_format: bool,
   *   date_modified_valid_format: bool,
   *   }
   *   Date property findings for the first article-type object found.
   */
  protected function extractSchemaDateProperties(string $html): array {
    $result = [
      'article_has_date_published' => FALSE,
      'article_has_date_modified' => FALSE,
      'date_published_valid_format' => FALSE,
      'date_modified_valid_format' => FALSE,
    ];

    $articleTypes = ['Article', 'NewsArticle', 'BlogPosting'];
    $iso8601Regex = '/^\d{4}-\d{2}-\d{2}/';

    preg_match_all(
      '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
      $html,
      $matches
    );

    foreach ($matches[1] ?? [] as $jsonText) {
      $data = json_decode(trim($jsonText), TRUE);
      if (!is_array($data)) {
        continue;
      }

      // Collect candidate items from either flat or @graph format.
      $items = [];
      if (isset($data['@graph']) && is_array($data['@graph'])) {
        $items = $data['@graph'];
      }
      else {
        $items = [$data];
      }

      foreach ($items as $item) {
        if (!is_array($item) || !isset($item['@type'])) {
          continue;
        }

        // Normalise @type to an array for uniform handling.
        $types = is_array($item['@type']) ? $item['@type'] : [$item['@type']];
        $isArticle = !empty(array_intersect($types, $articleTypes));

        if (!$isArticle) {
          continue;
        }

        // Found an article-type object — check date properties.
        if (isset($item['datePublished'])) {
          $result['article_has_date_published'] = TRUE;
          $result['date_published_valid_format'] = (bool) preg_match($iso8601Regex, (string) $item['datePublished']);
        }
        if (isset($item['dateModified'])) {
          $result['article_has_date_modified'] = TRUE;
          $result['date_modified_valid_format'] = (bool) preg_match($iso8601Regex, (string) $item['dateModified']);
        }

        // Stop after the first article-type item.
        return $result;
      }
    }

    return $result;
  }

  /**
   * Collects @type value(s) from a single decoded JSON-LD item.
   *
   * @param mixed $data
   *   Decoded JSON-LD item. Expected to be an associative array; non-arrays
   *   are silently skipped.
   * @param string[] $types
   *   Running list of types; updated in-place.
   */
  protected function collectSchemaType(mixed $data, array &$types): void {
    if (!is_array($data) || !isset($data['@type'])) {
      return;
    }
    // @type can be a string or an array of strings.
    $typeValue = $data['@type'];
    if (is_string($typeValue)) {
      $types[] = $typeValue;
    }
    elseif (is_array($typeValue)) {
      foreach ($typeValue as $t) {
        if (is_string($t)) {
          $types[] = $t;
        }
      }
    }
  }

  /**
   * Counts the number of application/ld+json script elements in HTML.
   *
   * @param string $html
   *   The HTML source.
   *
   * @return int
   *   The number of JSON-LD script blocks found.
   */
  protected function countJsonLdScripts(string $html): int {
    preg_match_all(
      '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>/si',
      $html,
      $matches
    );
    return count($matches[0] ?? []);
  }

}
