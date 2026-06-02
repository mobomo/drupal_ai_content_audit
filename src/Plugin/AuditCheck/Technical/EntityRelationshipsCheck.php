<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Technical;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks that nodes have a real author, taxonomy terms, and entity references. At site level checks taxonomy is configured.
 */
#[AuditCheck(
  id: 'entity_relationships',
  label: new TranslatableMarkup('Entity Relationships'),
  description: new TranslatableMarkup('Checks that nodes have a real author, taxonomy terms, and entity references. At site level checks taxonomy is configured.'),
  scope: 'site',
  category: 'Content Quality',
)]
class EntityRelationshipsCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   *
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('logger.factory')->get('ai_content_audit'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * This check handles both site-level and node-level contexts internally.
   */
  public function applies(?NodeInterface $node): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    if ($node === NULL) {
      return $this->checkEntityRelationshipsSiteLevel();
    }

    return $this->checkEntityRelationshipsForNode($node);
  }

  /**
   * Performs entity relationship checks for a specific node.
   */
  private function checkEntityRelationshipsForNode(NodeInterface $node): TechnicalAuditResult {
    $owner = $node->getOwner();
    $authorName = $owner ? $owner->getDisplayName() : 'Unknown';
    $hasRealAuthor = $owner && ($owner->id() > 0) && ($owner->getDisplayName() !== 'Anonymous');

    $taxonomyTermsByField = [];
    $entityRefFieldCount = 0;
    $totalRefsCount = 0;

    foreach ($node->getFieldDefinitions() as $fieldName => $fieldDefinition) {
      if ($fieldDefinition->getType() !== 'entity_reference') {
        continue;
      }

      $field = $node->get($fieldName);
      if ($field->isEmpty()) {
        continue;
      }

      $targetType = $fieldDefinition->getSetting('target_type');
      $referencedEntities = $field->referencedEntities();

      if (empty($referencedEntities)) {
        continue;
      }

      $entityRefFieldCount++;
      $totalRefsCount += count($referencedEntities);

      if ($targetType === 'taxonomy_term') {
        $termNames = [];
        foreach ($referencedEntities as $term) {
          $termNames[] = $term->label();
        }
        if (!empty($termNames)) {
          $taxonomyTermsByField[$fieldName] = $termNames;
        }
      }
    }

    $hasTaxonomyTerms = !empty($taxonomyTermsByField);
    $taxonomyTermsCount = array_sum(array_map('count', $taxonomyTermsByField));

    // Determine status.
    if ($hasRealAuthor && $hasTaxonomyTerms && $entityRefFieldCount >= 1) {
      return $this->pass(
        'Rich entity relationships: author set, '
          . $taxonomyTermsCount . ' taxonomy term(s) across '
          . count($taxonomyTermsByField) . ' field(s), and '
          . $entityRefFieldCount . ' entity reference field(s).',
        NULL,
        NULL,
        [
          'author_name' => $authorName,
          'has_real_author' => $hasRealAuthor,
          'taxonomy_terms' => $taxonomyTermsByField,
          'taxonomy_fields_count' => count($taxonomyTermsByField),
          'taxonomy_terms_used' => $taxonomyTermsCount,
          'entity_ref_count' => $entityRefFieldCount,
          'total_references_count' => $totalRefsCount,
        ],
      );
    }

    if ($hasRealAuthor || $hasTaxonomyTerms) {
      $parts = [];
      if ($hasRealAuthor) {
        $parts[] = 'author "' . $authorName . '" set';
      }
      if ($hasTaxonomyTerms) {
        $parts[] = $taxonomyTermsCount . ' taxonomy term(s)';
      }
      $missing = [];
      if (!$hasRealAuthor) {
        $missing[] = 'real author';
      }
      if (!$hasTaxonomyTerms) {
        $missing[] = 'taxonomy terms';
      }
      return $this->warning(
        'Partial entity relationships: ' . implode(' and ', $parts) . '. '
          . 'Missing: ' . implode(', ', $missing) . '.',
        NULL,
        NULL,
        [
          'author_name' => $authorName,
          'has_real_author' => $hasRealAuthor,
          'taxonomy_terms' => $taxonomyTermsByField,
          'taxonomy_fields_count' => count($taxonomyTermsByField),
          'taxonomy_terms_used' => $taxonomyTermsCount,
          'entity_ref_count' => $entityRefFieldCount,
          'total_references_count' => $totalRefsCount,
        ],
      );
    }

    return $this->fail(
      'No meaningful entity relationships found. Assign a named author, '
        . 'add taxonomy terms, and link to related content to improve LLM context.',
      NULL,
      NULL,
      [
        'author_name' => $authorName,
        'has_real_author' => $hasRealAuthor,
        'taxonomy_terms' => $taxonomyTermsByField,
        'taxonomy_fields_count' => count($taxonomyTermsByField),
        'taxonomy_terms_used' => $taxonomyTermsCount,
        'entity_ref_count' => $entityRefFieldCount,
        'total_references_count' => $totalRefsCount,
      ],
    );
  }

  /**
   * Performs a site-level entity relationship readiness check (no node).
   */
  private function checkEntityRelationshipsSiteLevel(): TechnicalAuditResult {
    $taxonomyEnabled = $this->moduleHandler->moduleExists('taxonomy');

    $vocabularyCount = 0;
    if ($taxonomyEnabled) {
      try {
        $vocabularyCount = (int) $this->entityTypeManager
          ->getStorage('taxonomy_vocabulary')
          ->getQuery()
          ->accessCheck(FALSE)
          ->count()
          ->execute();
      }
      catch (\Exception $e) {
        $this->logger->warning('Technical audit: could not count taxonomy vocabularies: @msg', ['@msg' => $e->getMessage()]);
      }
    }

    if (!$taxonomyEnabled) {
      return $this->fail(
        'Taxonomy module is not installed. Entity relationships and topic classification are essential for LLM content context.',
        NULL,
        NULL,
        [
          'taxonomy_enabled' => $taxonomyEnabled,
          'vocabulary_count' => $vocabularyCount,
        ],
      );
    }

    if ($vocabularyCount === 0) {
      return $this->warning(
        'Taxonomy module is installed but no vocabularies are configured. Create vocabularies and tag content to improve entity relationship richness.',
        NULL,
        NULL,
        [
          'taxonomy_enabled' => $taxonomyEnabled,
          'vocabulary_count' => $vocabularyCount,
        ],
      );
    }

    return $this->pass(
      'Taxonomy module is installed with ' . $vocabularyCount . ' vocabulary/vocabularies configured. Ensure content types use entity reference fields to tag with terms.',
      NULL,
      NULL,
      [
        'taxonomy_enabled' => $taxonomyEnabled,
        'vocabulary_count' => $vocabularyCount,
      ],
    );
  }

}
