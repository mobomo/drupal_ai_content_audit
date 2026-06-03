<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\Extractor;

use Drupal\user\UserInterface;
use Drupal\Core\Url;
use Drupal\ai_content_audit\Extractor\EntityContextTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EntityContextTrait.
 *
 * A concrete anonymous class that uses the trait is created in each test so
 * that the protected methods can be called via thin public proxy methods.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit\Extractor\EntityContextTrait
 */
class EntityContextTraitTest extends TestCase {

  /**
   * Creates a concrete test host that exposes the trait's protected methods.
   *
   * @return object
   *   An anonymous object with callBuildContentMetadataBlock() and
   *   callBuildEntityContextBlock() public wrappers.
   */
  private function createTraitHost(): object {
    return new class() {
      use EntityContextTrait;

      /**
       * Proxies buildContentMetadataBlock().
       *
       * @param \Drupal\node\NodeInterface $node
       *   The node under test.
       *
       * @return string
       *   Metadata block text.
       */
      public function callBuildContentMetadataBlock(NodeInterface $node): string {
        return $this->buildContentMetadataBlock($node);
      }

      /**
       * Proxies buildEntityContextBlock().
       *
       * @param \Drupal\node\NodeInterface $node
       *   The node under test.
       *
       * @return string
       *   Entity context block text.
       */
      public function callBuildEntityContextBlock(NodeInterface $node): string {
        return $this->buildEntityContextBlock($node);
      }

    };
  }

  /**
   * Creates a stdClass type field stub for tests.
   *
   * Supports optional bundle entity label via `$node->type->entity`.
   *
   * @param string|null $label
   *   Label from the bundle entity, or NULL when no bundle entity exists.
   *
   * @return \stdClass
   *   Object mimicking a type field item list.
   */
  private function makeTypeProp(?string $label): \stdClass {
    $typeProp = new \stdClass();
    if ($label !== NULL) {
      $bundleEntityStub = $this->createMock(EntityInterface::class);
      $bundleEntityStub->method('label')->willReturn($label);
      $typeProp->entity = $bundleEntityStub;
    }
    else {
      $typeProp->entity = NULL;
    }
    return $typeProp;
  }

  /**
   * Builds a NodeInterface mock with type, bundle, dates, and id.
   *
   * @param string $label
   *   Node title.
   * @param string $bundle
   *   Node bundle machine name.
   * @param int $nid
   *   Node ID.
   * @param int $created
   *   Created timestamp.
   * @param int $changed
   *   Changed timestamp.
   * @param string|null $bundleLabel
   *   Human-readable bundle label (NULL = use bundle as fallback).
   * @param string|\Exception $urlOrException
   *   URL string or exception to throw from toUrl().
   *
   * @return \Drupal\node\NodeInterface&\PHPUnit\Framework\MockObject\MockObject
   *   Configured node mock.
   */
  private function buildNodeMock(
    string $label = 'Test Title',
    string $bundle = 'article',
    int $nid = 1,
    int $created = 1700000000,
    int $changed = 1710000000,
    ?string $bundleLabel = 'Article',
    string|\Exception $urlOrException = '/node/1',
  ): NodeInterface {
    $node = $this->getMockBuilder(NodeInterface::class)
      ->addMethods(['__get'])
      ->getMock();

    $node->method('label')->willReturn($label);
    $node->method('bundle')->willReturn($bundle);
    $node->method('id')->willReturn($nid);
    $node->method('getCreatedTime')->willReturn($created);
    $node->method('getChangedTime')->willReturn($changed);

    // Configure $node->type->entity for bundle label resolution.
    $node->method('__get')->willReturnCallback(
      fn(string $name) => $name === 'type' ? $this->makeTypeProp($bundleLabel) : NULL
    );

    // Configure toUrl() behaviour.
    $urlMock = $this->createMock(Url::class);
    if ($urlOrException instanceof \Exception) {
      $node->method('toUrl')->willThrowException($urlOrException);
    }
    else {
      $urlMock->method('toString')->willReturn($urlOrException);
      $node->method('toUrl')->willReturn($urlMock);
    }

    return $node;
  }

  /*
   * ---------------------------------------------------------------------------
   * buildContentMetadataBlock() tests
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that the metadata block includes title, content type, dates, and URL.
   *
   * @covers ::buildContentMetadataBlock
   */
  public function testBuildContentMetadataBlockIncludesAllFields(): void {
    // Arrange.
    $host = $this->createTraitHost();
    $node = $this->buildNodeMock(
      label     : 'My Node Title',
      bundle    : 'article',
      nid       : 42,
      created   : mktime(0, 0, 0, 1, 1, 2024),
      changed   : mktime(0, 0, 0, 6, 1, 2024),
      bundleLabel: 'Article',
      urlOrException: '/articles/my-node-title',
    );

    // Act.
    $result = $host->callBuildContentMetadataBlock($node);

    // Assert.
    $this->assertStringContainsString('--- Content Metadata ---', $result);
    $this->assertStringContainsString('Title: My Node Title', $result);
    $this->assertStringContainsString('Content Type: Article', $result);
    $this->assertStringContainsString('Created: 2024-01-01', $result);
    $this->assertStringContainsString('Last Modified: 2024-06-01', $result);
    $this->assertStringContainsString('URL: /articles/my-node-title', $result);
  }

  /**
   * Tests fallback to /node/{nid} when toUrl() throws.
   *
   * @covers ::buildContentMetadataBlock
   */
  public function testBuildContentMetadataBlockHandlesUrlException(): void {
    // Arrange.
    $host = $this->createTraitHost();
    $node = $this->buildNodeMock(
      nid           : 99,
      urlOrException: new \Exception('Routing error'),
    );

    // Act.
    $result = $host->callBuildContentMetadataBlock($node);

    // Assert — fallback path must be /node/99.
    $this->assertStringContainsString('URL: /node/99', $result);
  }

  /**
   * Tests that bundle machine name is used when no bundle entity is available.
   *
   * @covers ::buildContentMetadataBlock
   */
  public function testBuildContentMetadataBlockFallsBackToBundleName(): void {
    // Arrange.
    $host = $this->createTraitHost();
    $node = $this->buildNodeMock(
      bundle     : 'landing_page',
    // Forces fallback.
      bundleLabel: NULL,
    );

    // Act.
    $result = $host->callBuildContentMetadataBlock($node);

    // Assert — raw bundle machine name used as fallback.
    $this->assertStringContainsString('Content Type: landing_page', $result);
  }

  /*
   * ---------------------------------------------------------------------------
   * buildEntityContextBlock() tests
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that a named author is present in the entity context block.
   *
   * @covers ::buildEntityContextBlock
   */
  public function testBuildEntityContextBlockIncludesAuthor(): void {
    // Arrange.
    $host = $this->createTraitHost();

    $owner = $this->createMock(UserInterface::class);
    $owner->method('getDisplayName')->willReturn('Jane Doe');

    $node = $this->getMockBuilder(NodeInterface::class)->getMock();
    $node->method('getOwner')->willReturn($owner);
    $node->method('getFieldDefinitions')->willReturn([]);

    // Act.
    $result = $host->callBuildEntityContextBlock($node);

    // Assert.
    $this->assertStringContainsString('--- Entity Context ---', $result);
    $this->assertStringContainsString('Author: Jane Doe', $result);
  }

  /**
   * Tests that "Anonymous" is shown when the owner cannot be resolved.
   *
   * @covers ::buildEntityContextBlock
   */
  public function testBuildEntityContextBlockAnonymousAuthor(): void {
    // Arrange.
    $host = $this->createTraitHost();

    $node = $this->getMockBuilder(NodeInterface::class)->getMock();
    $node->method('getOwner')->willReturn(NULL);
    $node->method('getFieldDefinitions')->willReturn([]);

    // Act.
    $result = $host->callBuildEntityContextBlock($node);

    // Assert.
    $this->assertStringContainsString('Author: Anonymous', $result);
  }

  /**
   * Tests taxonomy term names in entity_reference fields.
   *
   * Covers fields whose target_type is taxonomy_term.
   *
   * @covers ::buildEntityContextBlock
   */
  public function testBuildEntityContextBlockIncludesTaxonomyTerms(): void {
    // Arrange.
    $host = $this->createTraitHost();

    // Create two taxonomy term mocks.
    $term1 = $this->createMock(EntityInterface::class);
    $term1->method('label')->willReturn('Drupal');
    $term2 = $this->createMock(EntityInterface::class);
    $term2->method('label')->willReturn('PHP');

    // Field definition for a taxonomy reference.
    $fieldDef = $this->createMock(FieldDefinitionInterface::class);
    $fieldDef->method('getType')->willReturn('entity_reference');
    $fieldDef->method('getSetting')->with('target_type')->willReturn('taxonomy_term');
    $fieldDef->method('getLabel')->willReturn('Tags');

    // Field item list mock — non-empty with two referenced terms.
    $fieldList = $this->createMock(FieldItemListInterface::class);
    $fieldList->method('isEmpty')->willReturn(FALSE);
    $fieldList->method('referencedEntities')->willReturn([$term1, $term2]);

    // Owner mock.
    $owner = $this->createMock(UserInterface::class);
    $owner->method('getDisplayName')->willReturn('Admin');

    // Node mock.
    $node = $this->getMockBuilder(NodeInterface::class)->getMock();
    $node->method('getOwner')->willReturn($owner);
    $node->method('getFieldDefinitions')->willReturn(['field_tags' => $fieldDef]);
    $node->method('get')->with('field_tags')->willReturn($fieldList);

    // Act.
    $result = $host->callBuildEntityContextBlock($node);

    // Assert.
    $this->assertStringContainsString('Tags: Drupal, PHP', $result);
    $this->assertStringNotContainsString('Related Tags:', $result);
  }

  /**
   * Non-taxonomy entity references show "Related X: N items".
   *
   * @covers ::buildEntityContextBlock
   */
  public function testBuildEntityContextBlockIncludesRelatedEntityCounts(): void {
    // Arrange.
    $host = $this->createTraitHost();

    $fieldDef = $this->createMock(FieldDefinitionInterface::class);
    $fieldDef->method('getType')->willReturn('entity_reference');
    $fieldDef->method('getSetting')->with('target_type')->willReturn('node');
    $fieldDef->method('getLabel')->willReturn('Related Articles');

    $fieldList = $this->createMock(FieldItemListInterface::class);
    $fieldList->method('isEmpty')->willReturn(FALSE);
    $fieldList->method('count')->willReturn(3);

    $owner = $this->createMock(UserInterface::class);
    $owner->method('getDisplayName')->willReturn('Admin');

    $node = $this->getMockBuilder(NodeInterface::class)->getMock();
    $node->method('getOwner')->willReturn($owner);
    $node->method('getFieldDefinitions')->willReturn(['field_related' => $fieldDef]);
    $node->method('get')->with('field_related')->willReturn($fieldList);

    // Act.
    $result = $host->callBuildEntityContextBlock($node);

    // Assert.
    $this->assertStringContainsString('Related Related Articles: 3 items', $result);
  }

  /**
   * Tests that user entity reference fields are skipped entirely.
   *
   * @covers ::buildEntityContextBlock
   */
  public function testBuildEntityContextBlockSkipsUserReferenceFields(): void {
    // Arrange.
    $host = $this->createTraitHost();

    $fieldDef = $this->createMock(FieldDefinitionInterface::class);
    $fieldDef->method('getType')->willReturn('entity_reference');
    $fieldDef->method('getSetting')->with('target_type')->willReturn('user');
    $fieldDef->method('getLabel')->willReturn('Co-Author');

    $fieldList = $this->createMock(FieldItemListInterface::class);
    $fieldList->method('isEmpty')->willReturn(FALSE);
    $fieldList->method('count')->willReturn(1);

    $owner = $this->createMock(UserInterface::class);
    $owner->method('getDisplayName')->willReturn('Admin');

    $node = $this->getMockBuilder(NodeInterface::class)->getMock();
    $node->method('getOwner')->willReturn($owner);
    $node->method('getFieldDefinitions')->willReturn(['field_coauthor' => $fieldDef]);
    $node->method('get')->with('field_coauthor')->willReturn($fieldList);

    // Act.
    $result = $host->callBuildEntityContextBlock($node);

    // Assert — user reference must NOT appear as "Related Co-Author".
    $this->assertStringNotContainsString('Co-Author', $result);
    $this->assertStringNotContainsString('Related Co-Author', $result);
  }

  /**
   * Tests that empty entity reference fields are silently skipped.
   *
   * @covers ::buildEntityContextBlock
   */
  public function testBuildEntityContextBlockSkipsEmptyReferenceFields(): void {
    // Arrange.
    $host = $this->createTraitHost();

    $fieldDef = $this->createMock(FieldDefinitionInterface::class);
    $fieldDef->method('getType')->willReturn('entity_reference');
    $fieldDef->method('getSetting')->with('target_type')->willReturn('taxonomy_term');
    $fieldDef->method('getLabel')->willReturn('Category');

    $emptyFieldList = $this->createMock(FieldItemListInterface::class);
    $emptyFieldList->method('isEmpty')->willReturn(TRUE);

    $owner = $this->createMock(UserInterface::class);
    $owner->method('getDisplayName')->willReturn('Admin');

    $node = $this->getMockBuilder(NodeInterface::class)->getMock();
    $node->method('getOwner')->willReturn($owner);
    $node->method('getFieldDefinitions')->willReturn(['field_category' => $fieldDef]);
    $node->method('get')->with('field_category')->willReturn($emptyFieldList);

    // Act.
    $result = $host->callBuildEntityContextBlock($node);

    // Assert — empty field produces no output line.
    $this->assertStringNotContainsString('Category:', $result);
  }

}
