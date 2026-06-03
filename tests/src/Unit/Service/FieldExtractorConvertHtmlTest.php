<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\Service;

use Drupal\ai_content_audit\Plugin\ContentExtractor\FieldExtractor;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FieldExtractor::convertAndStripHtml().
 *
 * The method is protected, so it is exercised via ReflectionMethod to keep the
 * tests focused on the conversion logic in isolation without the overhead of
 * mocking the full field extraction pipeline.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit\Plugin\ContentExtractor\FieldExtractor
 */
class FieldExtractorConvertHtmlTest extends TestCase {

  /**
   * The extractor instance under test.
   */
  protected FieldExtractor $extractor;

  /**
   * Reflected convertAndStripHtml() method for direct invocation.
   */
  protected \ReflectionMethod $convertMethod;

  /**
   * Reflected stripHtml() method for direct invocation.
   */
  protected \ReflectionMethod $stripMethod;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->extractor = new FieldExtractor(
      [],
      'field_text',
      ['render_mode' => 'text'],
      $entityTypeManager,
    );

    $this->convertMethod = new \ReflectionMethod(FieldExtractor::class, 'convertAndStripHtml');
    $this->convertMethod->setAccessible(TRUE);

    $this->stripMethod = new \ReflectionMethod(FieldExtractor::class, 'stripHtml');
    $this->stripMethod->setAccessible(TRUE);
  }

  /*
   * ---------------------------------------------------------------------------
   * Heading conversion tests
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that h1–h6 tags are converted to markdown-style heading markers.
   *
   * @covers ::convertAndStripHtml
   */
  public function testConvertAndStripHtmlPreservesHeadings(): void {
    // Arrange.
    $html = '<h1>Page Title</h1>'
      . '<h2>Section One</h2>'
      . '<h3>Subsection</h3>'
      . '<h4>Level Four</h4>'
      . '<h5>Level Five</h5>'
      . '<h6>Level Six</h6>';

    // Act.
    $result = $this->convertMethod->invoke($this->extractor, $html);

    // Assert — each heading level must produce the correct marker prefix.
    $this->assertStringContainsString('# H1: Page Title', $result);
    $this->assertStringContainsString('## H2: Section One', $result);
    $this->assertStringContainsString('### H3: Subsection', $result);
    $this->assertStringContainsString('#### H4: Level Four', $result);
    $this->assertStringContainsString('##### H5: Level Five', $result);
    $this->assertStringContainsString('###### H6: Level Six', $result);
  }

  /**
   * Tests that nested tags within a heading are stripped inside the marker.
   *
   * @covers ::convertAndStripHtml
   */
  public function testConvertAndStripHtmlStripsTagsInsideHeadings(): void {
    // Arrange.
    $html = '<h2>Section <strong>Bold</strong> Title</h2>';

    // Act.
    $result = $this->convertMethod->invoke($this->extractor, $html);

    // Assert — inner HTML tags stripped, plain text preserved.
    $this->assertStringContainsString('## H2: Section Bold Title', $result);
    $this->assertStringNotContainsString('<strong>', $result);
  }

  /*
   * ---------------------------------------------------------------------------
   * Image alt-text extraction tests
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that an img with a non-empty alt attribute becomes [Image: alt].
   *
   * @covers ::convertAndStripHtml
   */
  public function testConvertAndStripHtmlExtractsImageAltText(): void {
    // Arrange.
    $html = '<p>Look at this: <img src="photo.jpg" alt="A beautiful sunset" /></p>';

    // Act.
    $result = $this->convertMethod->invoke($this->extractor, $html);

    // Assert.
    $this->assertStringContainsString('[Image: A beautiful sunset]', $result);
    $this->assertStringNotContainsString('<img', $result);
  }

  /**
   * Tests that an img with an empty alt attribute becomes [Image: no alt text].
   *
   * @covers ::convertAndStripHtml
   */
  public function testConvertAndStripHtmlHandlesEmptyAlt(): void {
    // Arrange — alt attribute present but empty string.
    $html = '<img src="decorative.png" alt="" />';

    // Act.
    $result = $this->convertMethod->invoke($this->extractor, $html);

    // Assert.
    $this->assertStringContainsString('[Image: no alt text]', $result);
  }

  /**
   * Tests that img with no alt attribute becomes [Image: no alt text].
   *
   * @covers ::convertAndStripHtml
   */
  public function testConvertAndStripHtmlHandlesMissingAlt(): void {
    // Arrange — no alt attribute on the img tag.
    $html = '<img src="foo.jpg">';

    // Act.
    $result = $this->convertMethod->invoke($this->extractor, $html);

    // Assert.
    $this->assertStringContainsString('[Image: no alt text]', $result);
  }

  /*
   * ---------------------------------------------------------------------------
   * Link extraction tests
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that anchor tags become [Link: text (href)] markers.
   *
   * @covers ::convertAndStripHtml
   */
  public function testConvertAndStripHtmlExtractsLinks(): void {
    // Arrange.
    $html = '<p>Visit our <a href="/about">About Us</a> page.</p>';

    // Act.
    $result = $this->convertMethod->invoke($this->extractor, $html);

    // Assert.
    $this->assertStringContainsString('[Link: About Us (/about)]', $result);
    $this->assertStringNotContainsString('<a ', $result);
  }

  /**
   * Tests that a link with only whitespace text falls back to the href.
   *
   * @covers ::convertAndStripHtml
   */
  public function testConvertAndStripHtmlFallsBackToHrefForEmptyAnchorText(): void {
    // Arrange — anchor text is blank after strip_tags.
    $html = '<a href="https://example.com">   </a>';

    // Act.
    $result = $this->convertMethod->invoke($this->extractor, $html);

    // Assert — href is used in place of empty text.
    $this->assertStringContainsString('[Link: https://example.com (https://example.com)]', $result);
  }

  /**
   * Tests that anchor tags containing nested HTML are handled correctly.
   *
   * @covers ::convertAndStripHtml
   */
  public function testConvertAndStripHtmlExtractsLinkTextFromNestedHtml(): void {
    // Arrange.
    $html = '<a href="/contact"><span class="icon"></span><strong>Contact</strong></a>';

    // Act.
    $result = $this->convertMethod->invoke($this->extractor, $html);

    // Assert — inner tags stripped, leaving only the text.
    $this->assertStringContainsString('[Link: Contact (/contact)]', $result);
  }

  /*
   * ---------------------------------------------------------------------------
   * Remaining-tag stripping and whitespace normalisation tests
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that non-converted HTML tags (div, span, p, etc.) are stripped.
   *
   * @covers ::convertAndStripHtml
   */
  public function testConvertAndStripHtmlStripsRemainingTags(): void {
    // Arrange.
    $html = '<div><p>Hello <span class="highlight">world</span></p></div>';

    // Act.
    $result = $this->convertMethod->invoke($this->extractor, $html);

    // Assert — plain text remains, no HTML markup.
    $this->assertStringContainsString('Hello world', $result);
    $this->assertStringNotContainsString('<div>', $result);
    $this->assertStringNotContainsString('<span', $result);
    $this->assertStringNotContainsString('<p>', $result);
  }

  /**
   * Tests that excessive consecutive newlines are collapsed to at most two.
   *
   * @covers ::convertAndStripHtml
   */
  public function testConvertAndStripHtmlNormalizesWhitespace(): void {
    // Arrange — HTML that produces multiple heading markers with blank lines.
    $html = '<h1>Title</h1>'
      . "\n\n\n\n"
      . '<h2>Subtitle</h2>'
      . "\n\n\n\n\n"
      . '<p>Paragraph text.</p>';

    // Act.
    $result = $this->convertMethod->invoke($this->extractor, $html);

    // Assert — result must not contain three or more consecutive newlines.
    $this->assertDoesNotMatch('/\n{3,}/', $result);
  }

  /**
   * Tests that HTML entities are decoded in the final output.
   *
   * @covers ::convertAndStripHtml
   */
  public function testConvertAndStripHtmlDecodesHtmlEntities(): void {
    // Arrange.
    $html = '<p>Price: &pound;10 &amp; more &lt;details&gt;</p>';

    // Act.
    $result = $this->convertMethod->invoke($this->extractor, $html);

    // Assert — entities decoded, tags stripped.
    $this->assertStringContainsString('£10', $result);
    $this->assertStringContainsString('& more', $result);
    // Literal "<details>" from &lt;details&gt; must not become a tag.
    $this->assertStringContainsString('<details>', $result);
  }

  /*
   * ---------------------------------------------------------------------------
   * stripHtml() helper tests
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that stripHtml() removes all HTML tags and collapses whitespace.
   *
   * @covers ::stripHtml
   */
  public function testStripHtmlRemovesAllTagsAndNormalizesWhitespace(): void {
    // Arrange.
    $html = '<p>Hello   <strong>World</strong></p><br/>How are you?';

    // Act.
    $result = $this->stripMethod->invoke($this->extractor, $html);

    // Assert.
    $this->assertEquals('Hello World How are you?', $result);
  }

  /**
   * Tests that stripHtml() preserves the pre-inserted structural markers.
   *
   * ConvertAndStripHtml() inserts [Image: ...] and [Link: ...] markers BEFORE
   * calling stripHtml().  stripHtml() must not touch those marker strings since
   * they contain no HTML tags.
   *
   * @covers ::stripHtml
   */
  public function testStripHtmlPreservesStructuralMarkers(): void {
    // Arrange — simulate post-conversion intermediate string.
    $intermediate = ' [Image: A cat] some text [Link: Home (/)] ';

    // Act.
    $result = $this->stripMethod->invoke($this->extractor, $intermediate);

    // Assert.
    $this->assertStringContainsString('[Image: A cat]', $result);
    $this->assertStringContainsString('[Link: Home (/)]', $result);
  }

}
