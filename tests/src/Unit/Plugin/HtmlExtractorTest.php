<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\Plugin;

use Drupal\ai_content_audit\Plugin\ContentExtractor\HtmlExtractor;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for HtmlExtractor::convertHtmlToStructuredText().
 *
 * The method is protected and is exercised via ReflectionMethod.  Dependencies
 * that the method does not actually use (entity type manager, renderer, config
 * factory) are replaced with light mocks; only the request stack needs real
 * configuration because convertHtmlToStructuredText() internally calls
 * getSiteHost() to classify links as internal or external.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit\Plugin\ContentExtractor\HtmlExtractor
 */
class HtmlExtractorTest extends TestCase {

  /**
   * HtmlExtractor instance under test.
   */
  protected HtmlExtractor $extractor;

  /**
   * Reflected convertHtmlToStructuredText() method.
   */
  protected \ReflectionMethod $convertMethod;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->extractor = $this->buildExtractor('example.com');

    $this->convertMethod = new \ReflectionMethod(
      HtmlExtractor::class,
      'convertHtmlToStructuredText',
    );
    $this->convertMethod->setAccessible(TRUE);
  }

  /**
   * Builds an HtmlExtractor with the given site host for link classification.
   *
   * @param string $host
   *   Host name returned by the mock request (e.g. 'example.com').
   *
   * @return \Drupal\ai_content_audit\Plugin\ContentExtractor\HtmlExtractor
   */
  private function buildExtractor(string $host): HtmlExtractor {
    $request = $this->createMock(Request::class);
    $request->method('getHost')->willReturn($host);

    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn($request);

    return new HtmlExtractor(
      [],
      'html_rendered',
      ['render_mode' => 'html'],
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(RendererInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $requestStack,
    );
  }

  /**
   * Convenience wrapper that invokes convertHtmlToStructuredText().
   *
   * @param string $html
   * @param \Drupal\ai_content_audit\Plugin\ContentExtractor\HtmlExtractor|null $extractor
   *   Optional extractor override (for host-specific tests).
   *
   * @return string
   */
  private function convert(string $html, ?HtmlExtractor $extractor = NULL): string {
    return $this->convertMethod->invoke($extractor ?? $this->extractor, $html);
  }

  // ---------------------------------------------------------------------------
  // Heading conversion
  // ---------------------------------------------------------------------------

  /**
   * Tests that h1–h6 elements are converted to markdown-style markers.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlPreservesHeadingHierarchy(): void {
    // Arrange.
    $html = '<h1>Main Title</h1>'
      . '<h2>Section Two</h2>'
      . '<h3>Sub Section</h3>'
      . '<h4>Level Four</h4>'
      . '<h5>Level Five</h5>'
      . '<h6>Level Six</h6>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('# H1: Main Title', $result);
    $this->assertStringContainsString('## H2: Section Two', $result);
    $this->assertStringContainsString('### H3: Sub Section', $result);
    $this->assertStringContainsString('#### H4: Level Four', $result);
    $this->assertStringContainsString('##### H5: Level Five', $result);
    $this->assertStringContainsString('###### H6: Level Six', $result);
  }

  // ---------------------------------------------------------------------------
  // Image alt-text conversion
  // ---------------------------------------------------------------------------

  /**
   * Tests that img elements become [Image: alt text] markers.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlExtractsImageAltText(): void {
    // Arrange.
    $html = '<article><p>See figure: <img src="chart.png" alt="Sales chart for Q1"></p></article>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('[Image: Sales chart for Q1]', $result);
    $this->assertStringNotContainsString('<img', $result);
  }

  /**
   * Tests that img elements without alt become [Image: no alt text].
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlHandlesImagesWithoutAlt(): void {
    // Arrange.
    $html = '<p><img src="decorative.png"></p>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('[Image: no alt text]', $result);
  }

  // ---------------------------------------------------------------------------
  // Link classification
  // ---------------------------------------------------------------------------

  /**
   * Tests that path-relative links are classified as internal.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlClassifiesInternalLinks(): void {
    // Arrange — href starts with "/", site host is 'example.com'.
    $html = '<p><a href="/about">About Us</a></p>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('[Link: About Us (internal: /about)]', $result);
  }

  /**
   * Tests that fragment-only hrefs are classified as internal.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlClassifiesFragmentLinksAsInternal(): void {
    // Arrange.
    $html = '<p><a href="#section-2">Jump to section 2</a></p>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('[Link: Jump to section 2 (internal: #section-2)]', $result);
  }

  /**
   * Tests that same-host absolute URLs are classified as internal.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlClassifiesSameHostLinksAsInternal(): void {
    // Arrange — extractor's site host is 'example.com'.
    $html = '<p><a href="https://example.com/blog">Our Blog</a></p>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('[Link: Our Blog (internal: https://example.com/blog)]', $result);
  }

  /**
   * Tests that cross-domain links are classified as external.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlClassifiesExternalLinks(): void {
    // Arrange.
    $html = '<p><a href="https://other.org/page">External Page</a></p>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('[Link: External Page (external: https://other.org/page)]', $result);
  }

  /**
   * Tests that when no request is available (CLI), all non-path links are external.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlTreatsAllLinksAsExternalWhenNoHost(): void {
    // Arrange — build extractor whose requestStack returns NULL (CLI mode).
    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn(NULL);

    $extractor = new HtmlExtractor(
      [], 'html_rendered', ['render_mode' => 'html'],
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(RendererInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $requestStack,
    );
    $html = '<p><a href="https://example.com/page">A Link</a></p>';

    // Act.
    $result = $this->convertMethod->invoke($extractor, $html);

    // Assert — without a host, absolute URLs are external.
    $this->assertStringContainsString('external:', $result);
  }

  // ---------------------------------------------------------------------------
  // Table conversion
  // ---------------------------------------------------------------------------

  /**
   * Tests that table elements are converted to [Table]...[/Table] format.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlHandlesTables(): void {
    // Arrange.
    $html = '<table>'
      . '<tr><th>Name</th><th>Score</th></tr>'
      . '<tr><td>Alice</td><td>95</td></tr>'
      . '<tr><td>Bob</td><td>87</td></tr>'
      . '</table>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('[Table]', $result);
    $this->assertStringContainsString('[/Table]', $result);
    $this->assertStringContainsString('| Name | Score |', $result);
    $this->assertStringContainsString('| Alice | 95 |', $result);
    $this->assertStringContainsString('| Bob | 87 |', $result);
    $this->assertStringNotContainsString('<table>', $result);
    $this->assertStringNotContainsString('<tr>', $result);
  }

  /**
   * Tests that nested tables are processed innermost-first.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlHandlesNestedTables(): void {
    // Arrange — inner table nested inside outer table cell.
    $html = '<table>'
      . '<tr><td>Outer<table><tr><td>Inner</td></tr></table></td></tr>'
      . '</table>';

    // Act.
    $result = $this->convert($html);

    // Assert — both tables must be converted; two [Table] markers expected.
    $this->assertSame(2, substr_count($result, '[Table]'));
    $this->assertSame(2, substr_count($result, '[/Table]'));
    $this->assertStringContainsString('Inner', $result);
  }

  // ---------------------------------------------------------------------------
  // List conversion
  // ---------------------------------------------------------------------------

  /**
   * Tests that ul/li elements are converted to bullet markers.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlHandlesUnorderedLists(): void {
    // Arrange.
    $html = '<ul><li>First item</li><li>Second item</li><li>Third item</li></ul>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('• First item', $result);
    $this->assertStringContainsString('• Second item', $result);
    $this->assertStringContainsString('• Third item', $result);
    $this->assertStringNotContainsString('<li>', $result);
  }

  /**
   * Tests that ol/li elements are converted to numbered markers.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlHandlesOrderedLists(): void {
    // Arrange.
    $html = '<ol><li>Step one</li><li>Step two</li><li>Step three</li></ol>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('1. Step one', $result);
    $this->assertStringContainsString('2. Step two', $result);
    $this->assertStringContainsString('3. Step three', $result);
  }

  // ---------------------------------------------------------------------------
  // Navigation chrome stripping
  // ---------------------------------------------------------------------------

  /**
   * Tests that nav, header, and footer elements are stripped.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlStripsNavAndFooter(): void {
    // Arrange.
    $html = '<nav><a href="/">Home</a><a href="/about">About</a></nav>'
      . '<header><h1>Site Logo</h1></header>'
      . '<main><p>Body content here.</p></main>'
      . '<footer><p>Copyright 2024</p></footer>';

    // Act.
    $result = $this->convert($html);

    // Assert — nav/header/footer content must be absent; body content present.
    $this->assertStringContainsString('Body content here.', $result);
    $this->assertStringNotContainsString('Site Logo', $result);
    $this->assertStringNotContainsString('Copyright 2024', $result);
    // Navigation links are inside <nav> and should therefore be stripped.
    $this->assertStringNotContainsString('[Link: Home', $result);
  }

  /**
   * Tests that script and style elements (and their text) are stripped.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlStripsScriptsAndStyles(): void {
    // Arrange.
    $html = '<style>body { font-size: 16px; }</style>'
      . '<script>var x = 1;</script>'
      . '<p>Real content.</p>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('Real content.', $result);
    $this->assertStringNotContainsString('font-size', $result);
    $this->assertStringNotContainsString('var x', $result);
  }

  /**
   * Tests that visually-hidden elements are stripped.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlStripsVisuallyHiddenElements(): void {
    // Arrange.
    $html = '<p>Visible text.</p>'
      . '<span class="visually-hidden">Screen reader only</span>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('Visible text.', $result);
    $this->assertStringNotContainsString('Screen reader only', $result);
  }

  // ---------------------------------------------------------------------------
  // Edge cases
  // ---------------------------------------------------------------------------

  /**
   * Tests that an empty string input returns an empty string.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlHandlesEmptyInput(): void {
    // Act.
    $result = $this->convert('');

    // Assert.
    $this->assertSame('', $result);
  }

  /**
   * Tests that whitespace-only input returns an empty string.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlHandlesWhitespaceOnlyInput(): void {
    // Act.
    $result = $this->convert("   \n\t  ");

    // Assert.
    $this->assertSame('', $result);
  }

  /**
   * Tests that consecutive newlines in output are collapsed to at most two.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlNormalizesConsecutiveNewlines(): void {
    // Arrange — multiple headings produce newlines between them.
    $html = '<h1>A</h1>'
      . '<h2>B</h2>'
      . '<h3>C</h3>'
      . '<p>Body paragraph.</p>';

    // Act.
    $result = $this->convert($html);

    // Assert — output must not contain three or more consecutive newlines.
    $this->assertDoesNotMatch('/\n{3,}/', $result);
  }

  /**
   * Tests that HTML entities in text content are decoded.
   *
   * @covers ::convertHtmlToStructuredText
   */
  public function testConvertHtmlDecodesHtmlEntities(): void {
    // Arrange.
    $html = '<p>Price: &pound;10 &amp; VAT &mdash; included</p>';

    // Act.
    $result = $this->convert($html);

    // Assert.
    $this->assertStringContainsString('£10', $result);
    $this->assertStringContainsString('& VAT', $result);
    $this->assertStringContainsString('—', $result);
  }

}
