<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\Service;

use Drupal\ai_content_audit\Plugin\ContentExtractor\FieldExtractor;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FieldExtractor plugin.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit\Plugin\ContentExtractor\FieldExtractor
 */
class FieldExtractorTest extends TestCase {

  protected FieldExtractor $extractor;
  protected EntityTypeManagerInterface $entityTypeManager;

  protected function setUp(): void {
    parent::setUp();
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->extractor = new FieldExtractor(
      [],
      'field_text',
      ['render_mode' => 'text'],
      $this->entityTypeManager,
    );
  }

  /**
   * Tests that the node title is always included in the output.
   *
   * @covers ::extractForNode
   */
  public function testTitleIsAlwaysIncluded(): void {
    $node = $this->createMockNode('Test Article Title', []);

    // No display, no extra fields.
    $this->entityTypeManager->method('getStorage')->willReturn(
      $this->createConfiguredMock(EntityStorageInterface::class, ['load' => NULL])
    );

    $result = $this->extractor->extractForNode($node);
    $this->assertStringContainsString('Title: Test Article Title', $result);
  }

  /**
   * Tests that non-extractable field types are skipped.
   *
   * @covers ::extractForNode
   */
  public function testNonExtractableFieldsAreSkipped(): void {
    $intDefinition = $this->createMock(FieldDefinitionInterface::class);
    $intDefinition->method('getType')->willReturn('integer');
    $intDefinition->method('getLabel')->willReturn('Count');

    $node = $this->createMockNode('Title', ['field_count' => $intDefinition]);

    $this->entityTypeManager->method('getStorage')->willReturn(
      $this->createConfiguredMock(EntityStorageInterface::class, ['load' => NULL])
    );

    $result = $this->extractor->extractForNode($node);
    $this->assertStringNotContainsString('Count:', $result);
  }

  /**
   * Tests stripHtml removes tags and normalizes whitespace.
   *
   * @covers ::stripHtml
   */
  public function testStripHtmlRemovesTagsAndNormalizesWhitespace(): void {
    $reflection = new \ReflectionMethod(FieldExtractor::class, 'stripHtml');
    $reflection->setAccessible(TRUE);

    $html = '<p>Hello   <strong>World</strong></p><br/>How are you?';
    $result = $reflection->invoke($this->extractor, $html);
    $this->assertEquals('Hello World How are you?', $result);
  }

  /**
   * Tests that a node with only unsupported field types produces only its title.
   *
   * When every field on a node is of a non-extractable type (e.g. 'integer')
   * the output should consist solely of the "Title: …" line — the unsupported
   * fields must be absent from the result.
   *
   * @covers ::extractForNode
   */
  public function testExtractForNodeReturnsOnlyTitleForUnsupportedFieldType(): void {
    // An 'integer' field is not in EXTRACTABLE_FIELD_TYPES.
    $intDefinition = $this->createMock(FieldDefinitionInterface::class);
    $intDefinition->method('getType')->willReturn('integer');
    $intDefinition->method('getLabel')->willReturn('Page Count');

    $node = $this->createMockNode('My Node', ['field_page_count' => $intDefinition]);

    // No display configured — forces display check to pass (NULL is falsy).
    $this->entityTypeManager->method('getStorage')->willReturn(
      $this->createConfiguredMock(EntityStorageInterface::class, ['load' => NULL])
    );

    $result = $this->extractor->extractForNode($node);

    // The result must start with the title and contain nothing else.
    $this->assertStringContainsString('Title: My Node', $result);
    $this->assertStringNotContainsString('Page Count', $result);
    // Trim ensures we don't count leading/trailing whitespace as "extra" content.
    $this->assertEquals('Title: My Node', trim($result));
  }

  /**
   * Tests that extractForNode concatenates values from multiple text fields.
   *
   * Two string-type fields should both appear in the output, separated by the
   * double-newline delimiter used by extractForNode().
   *
   * To avoid the complexity of mocking Drupal's FieldItemList iterator, we
   * create an anonymous subclass that overrides the protected extractFieldText()
   * so we can assert the concatenation logic in isolation.
   *
   * @covers ::extractForNode
   */
  public function testExtractForNodeConcatenatesMultipleFields(): void {
    // --- field definitions ---
    $subtitleDef = $this->createMock(FieldDefinitionInterface::class);
    $subtitleDef->method('getType')->willReturn('string');
    $subtitleDef->method('getLabel')->willReturn('Subtitle');

    $bodyDef = $this->createMock(FieldDefinitionInterface::class);
    $bodyDef->method('getType')->willReturn('text_long');
    $bodyDef->method('getLabel')->willReturn('Body');

    // --- node mock ---
    // Use createMockNode for label/bundle/id/getFieldDefinitions.
    // Additionally stub get() so isEmpty() can be checked per field.
    $node = $this->createMock(NodeInterface::class);
    $node->method('label')->willReturn('Multi Field Node');
    $node->method('bundle')->willReturn('article');
    $node->method('id')->willReturn(5);
    $node->method('getFieldDefinitions')->willReturn([
      'field_subtitle' => $subtitleDef,
      'field_body'     => $bodyDef,
    ]);

    // Both fields are non-empty so the extractor will call extractFieldText().
    $nonEmptyField = $this->createMock(FieldItemListInterface::class);
    $nonEmptyField->method('isEmpty')->willReturn(FALSE);

    $node->method('get')->willReturn($nonEmptyField);

    // No display configured — all extractable fields pass the display check.
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn(
      $this->createConfiguredMock(EntityStorageInterface::class, ['load' => NULL])
    );

    // Anonymous subclass: override extractFieldText() to return predictable
    // values so we can verify concatenation without touching FieldItemList.
    $extractor = new class([], 'field_text', ['render_mode' => 'text'], $entityTypeManager) extends FieldExtractor {

      protected function extractFieldText(NodeInterface $node, string $field_name, string $field_type): string {
        return match ($field_name) {
          'field_subtitle' => 'The subtitle text',
          'field_body'     => 'The body text',
          default          => '',
        };
      }

    };

    $result = $extractor->extractForNode($node);

    $this->assertStringContainsString('Subtitle: The subtitle text', $result);
    $this->assertStringContainsString('Body: The body text', $result);
    // Both fields must be present in the same output string.
    $this->assertStringContainsString('Title: Multi Field Node', $result);
  }

  /**
   * Creates a mock NodeInterface.
   */
  protected function createMockNode(string $label, array $fieldDefinitions): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('label')->willReturn($label);
    $node->method('bundle')->willReturn('article');
    $node->method('id')->willReturn(1);
    $node->method('getFieldDefinitions')->willReturn($fieldDefinitions);

    return $node;
  }

}
