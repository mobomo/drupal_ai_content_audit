<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\Service;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\ai_content_audit\Plugin\Manager\AuditCheckManager;
use Drupal\ai_content_audit\Service\TechnicalAuditService;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for TechnicalAuditService new check methods.
 *
 * Tests cover:
 *   - checkSchemaMarkup() — schema.org JSON-LD parsing and status
 *   - checkCanonicalUrl() — live canonical tag verification
 *   - checkEntityRelationships() — node and site entity context checks.
 *
 * Note: methods that call Url::fromRoute() (node-level schema/canonical checks)
 * require a bootstrapped Drupal container and are therefore tested in Kernel
 * tests.  All tests here exercise the site-level code path (node = NULL) for
 * schema / canonical, and the full node path for entity relationships (which
 * does not use Url::fromRoute()).
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit\Service\TechnicalAuditService
 */
class TechnicalAuditServiceTest extends TestCase {

  /**
   * Mock HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * Mock module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Mock entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Mock request stack (returns site base URL 'http://example.com').
   */
  protected RequestStack $requestStack;

  /**
   * Mock config factory (returns a config object whose get() returns NULL).
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Wire a request that returns a predictable base URL.
    $request = $this->createMock(Request::class);
    $request->method('getSchemeAndHttpHost')->willReturn('http://example.com');

    $this->requestStack = $this->createMock(RequestStack::class);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    // Wire a config factory whose get() chain returns NULL without throwing.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')->willReturn($config);
  }

  /*
   * ---------------------------------------------------------------------------
   * Service factory helpers
   * ---------------------------------------------------------------------------
   */

  /**
   * Builds a TechnicalAuditService with the shared test dependencies.
   */
  private function buildService(): TechnicalAuditService {
    return new TechnicalAuditService(
      $this->httpClient,
      $this->configFactory,
      $this->createMock(LoggerInterface::class),
      $this->createMock(CacheBackendInterface::class),
      $this->requestStack,
      $this->moduleHandler,
      $this->entityTypeManager,
      $this->createMock(AuditCheckManager::class),
    );
  }

  /**
   * Builds a mock HTTP response whose body is the given HTML string.
   *
   * @param string $html
   *   HTML body content.
   * @param int $statusCode
   *   HTTP status code (default 200).
   *
   * @return \Psr\Http\Message\ResponseInterface&\PHPUnit\Framework\MockObject\MockObject
   *   Mock HTTP response.
   */
  private function buildHttpResponse(string $html, int $statusCode = 200): ResponseInterface {
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn($html);
    $body->method('getContents')->willReturn($html);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn($statusCode);
    $response->method('getBody')->willReturn($body);

    return $response;
  }

  /**
   * Creates a GuzzleException that can be thrown to simulate HTTP failures.
   *
   * Returns an anonymous class that extends RuntimeException and implements
   * GuzzleException so it is caught by the service's catch clause.
   */
  private function buildGuzzleException(string $message = 'Connection failed'): GuzzleException {
    return new class($message) extends \RuntimeException implements GuzzleException {};
  }

  /*
   * ---------------------------------------------------------------------------
   * checkSchemaMarkup() tests
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that ≥3 distinct desired schema types result in a 'pass' status.
   *
   * @covers ::checkSchemaMarkup
   */
  public function testCheckSchemaMarkupPassesWithMultipleTypes(): void {
    // Arrange — HTML with three separate JSON-LD blocks.
    $html = '<html><head>'
      . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"Test"}</script>'
      . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[]}</script>'
      . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"ACME"}</script>'
      . '</head><body><p>Content</p></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);

    // Act.
    $result = $this->buildService()->checkSchemaMarkup(NULL);

    // Assert.
    $this->assertSame('pass', $result->status);
    $this->assertSame('schema_markup', $result->check);
    $this->assertStringContainsString('Article', $result->description);
    $this->assertStringContainsString('BreadcrumbList', $result->description);
    $this->assertStringContainsString('Organization', $result->description);
  }

  /**
   * Tests that exactly 1 desired schema type results in a 'warning' status.
   *
   * @covers ::checkSchemaMarkup
   */
  public function testCheckSchemaMarkupWarnsWithFewTypes(): void {
    // Arrange — Only one recognised schema type.
    $html = '<html><head>'
      . '<script type="application/ld+json">{"@type":"Article"}</script>'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);

    // Act.
    $result = $this->buildService()->checkSchemaMarkup(NULL);

    // Assert.
    $this->assertSame('warning', $result->status);
    $this->assertStringContainsString('Partial Schema.org coverage', $result->description);
  }

  /**
   * Tests that no JSON-LD scripts at all result in a 'fail' status.
   *
   * @covers ::checkSchemaMarkup
   */
  public function testCheckSchemaMarkupFailsWithNoSchema(): void {
    // Arrange — HTML with no ld+json scripts.
    $html = '<html><head><title>No Schema</title></head><body><p>Plain page.</p></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);

    // Act.
    $result = $this->buildService()->checkSchemaMarkup(NULL);

    // Assert.
    $this->assertSame('fail', $result->status);
    $this->assertStringContainsString('No Schema.org', $result->description);
  }

  /**
   * Tests that @graph-wrapped JSON-LD blocks are correctly parsed.
   *
   * @covers ::checkSchemaMarkup
   */
  public function testCheckSchemaMarkupHandlesGraphArrays(): void {
    // Arrange — Single script block using @graph format.
    $graphJson = json_encode([
      '@context' => 'https://schema.org',
      '@graph' => [
        ['@type' => 'WebPage', 'name' => 'Home'],
        ['@type' => 'Organization', 'name' => 'ACME Corp'],
        ['@type' => 'BreadcrumbList', 'itemListElement' => []],
        ['@type' => 'Article', 'headline' => 'News Item'],
      ],
    ]);
    $html = '<html><head>'
      . '<script type="application/ld+json">' . $graphJson . '</script>'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);

    // Act.
    $result = $this->buildService()->checkSchemaMarkup(NULL);

    // Assert — 4 desired types from a single @graph block → pass.
    $this->assertSame('pass', $result->status);
    $details = $result->details;
    $this->assertContains('WebPage', $details['schema_types_found']);
    $this->assertContains('Organization', $details['schema_types_found']);
    $this->assertContains('BreadcrumbList', $details['schema_types_found']);
    $this->assertContains('Article', $details['schema_types_found']);
  }

  /**
   * HTTP failure during schema check returns warning, not a crash.
   *
   * @covers ::checkSchemaMarkup
   */
  public function testCheckSchemaMarkupHandlesHttpFailure(): void {
    // Arrange — HTTP client throws a Guzzle exception.
    $this->httpClient
      ->method('request')
      ->willThrowException($this->buildGuzzleException('cURL error 6: could not resolve host'));

    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);

    // Act.
    $result = $this->buildService()->checkSchemaMarkup(NULL);

    // Assert — graceful degradation, not a fatal exception.
    $this->assertSame('warning', $result->status);
    $this->assertStringContainsString('Unable to fetch', $result->description);
  }

  /**
   * Tests that JSON script blocks with unrecognised types still count scripts.
   *
   * @covers ::checkSchemaMarkup
   */
  public function testCheckSchemaMarkupCountsScriptsEvenWithUnrecognisedTypes(): void {
    // Arrange — One ld+json script but with an unrecognised @type.
    $html = '<html><head>'
      . '<script type="application/ld+json">{"@type":"CustomSchema","name":"test"}</script>'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);

    // Act.
    $result = $this->buildService()->checkSchemaMarkup(NULL);

    // Assert — 0 desired types with scripts found → fail message.
    $this->assertSame('fail', $result->status);
    $this->assertStringContainsString('JSON-LD scripts found but no recognised', $result->description);
    $this->assertSame(1, $result->details['total_scripts']);
  }

  /*
   * ---------------------------------------------------------------------------
   * checkCanonicalUrl() tests
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that a matching canonical tag produces 'pass' status.
   *
   * @covers ::checkCanonicalUrl
   */
  public function testCheckCanonicalUrlPassesWithValidTag(): void {
    // Arrange — page HTML where canonical URL matches http://example.com/.
    $html = '<html><head>'
      . '<link rel="canonical" href="http://example.com/">'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);

    // Act.
    $result = $this->buildService()->checkCanonicalUrl(NULL);

    // Assert.
    $this->assertSame('pass', $result->status);
    $this->assertSame('canonical_url', $result->check);
    $this->assertTrue($result->details['canonical_found']);
    $this->assertTrue($result->details['canonical_valid']);
  }

  /**
   * Tests that a canonical tag pointing to a different URL produces 'warning'.
   *
   * @covers ::checkCanonicalUrl
   */
  public function testCheckCanonicalUrlWarnsWithMismatchedUrl(): void {
    // Arrange — canonical points somewhere else.
    $html = '<html><head>'
      . '<link rel="canonical" href="https://other-domain.com/page">'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);

    // Act.
    $result = $this->buildService()->checkCanonicalUrl(NULL);

    // Assert.
    $this->assertSame('warning', $result->status);
    $this->assertTrue($result->details['canonical_found']);
    $this->assertFalse($result->details['canonical_valid']);
    $this->assertStringContainsString('unexpected URL', $result->description);
  }

  /**
   * Tests that no canonical tag and no metatag module produces 'fail'.
   *
   * @covers ::checkCanonicalUrl
   */
  public function testCheckCanonicalUrlFailsWithNoTag(): void {
    // Arrange — no canonical tags in the HTML, metatag module not installed.
    $html = '<html><head><title>No Canonical</title></head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')
      ->with('metatag')
      ->willReturn(FALSE);

    // Act.
    $result = $this->buildService()->checkCanonicalUrl(NULL);

    // Assert.
    $this->assertSame('fail', $result->status);
    $this->assertFalse($result->details['canonical_found']);
    $this->assertStringContainsString('Metatag module is not installed', $result->description);
  }

  /**
   * Tests that no canonical tag + metatag module installed produces 'warning'.
   *
   * @covers ::checkCanonicalUrl
   */
  public function testCheckCanonicalUrlSiteLevelChecksModule(): void {
    // Arrange — no canonical in HTML but metatag is installed.
    $html = '<html><head><title>Has Metatag Module</title></head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')
      ->with('metatag')
      ->willReturn(TRUE);

    // Act.
    $result = $this->buildService()->checkCanonicalUrl(NULL);

    // Assert — metatag installed but no actual tag → warning.
    $this->assertSame('warning', $result->status);
    $this->assertTrue($result->details['module_installed']);
    $this->assertFalse($result->details['canonical_found']);
  }

  /**
   * HTTP failure for canonical check falls back to metatag presence.
   *
   * @covers ::checkCanonicalUrl
   */
  public function testCheckCanonicalUrlHandlesHttpFailureWithMetatag(): void {
    // Arrange — HTTP throws but metatag is installed.
    $this->httpClient
      ->method('request')
      ->willThrowException($this->buildGuzzleException('Timeout'));

    $this->moduleHandler->method('moduleExists')
      ->with('metatag')
      ->willReturn(TRUE);

    // Act.
    $result = $this->buildService()->checkCanonicalUrl(NULL);

    // Assert — HTTP failed + metatag installed → warning.
    $this->assertSame('warning', $result->status);
    $this->assertTrue($result->details['http_check_failed']);
    $this->assertTrue($result->details['module_installed']);
  }

  /**
   * Tests that canonical tag with reversed attribute order is still detected.
   *
   * @covers ::checkCanonicalUrl
   */
  public function testCheckCanonicalUrlDetectsReversedAttributeOrder(): void {
    // Arrange — href comes before rel in the tag.
    $html = '<html><head>'
      . '<link href="http://example.com/" rel="canonical">'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);

    // Act.
    $result = $this->buildService()->checkCanonicalUrl(NULL);

    // Assert — both attribute orders must be matched.
    $this->assertSame('pass', $result->status);
    $this->assertTrue($result->details['canonical_found']);
  }

  /*
   * ---------------------------------------------------------------------------
   * checkEntityRelationships() — node-level tests
   * ---------------------------------------------------------------------------
   */

  /**
   * Builds a UserInterface mock representing a real (non-anonymous) author.
   *
   * @param int $uid
   *   User ID.
   * @param string $displayName
   *   Display name returned by getDisplayName().
   *
   * @return \Drupal\user\UserInterface&\PHPUnit\Framework\MockObject\MockObject
   *   Mock author user.
   */
  private function buildAuthorMock(int $uid = 5, string $displayName = 'Jane Doe'): UserInterface {
    $owner = $this->createMock(UserInterface::class);
    $owner->method('id')->willReturn($uid);
    $owner->method('getDisplayName')->willReturn($displayName);
    return $owner;
  }

  /**
   * Builds a NodeInterface mock with the specified owner and field definitions.
   *
   * @param \Drupal\user\UserInterface|null $owner
   *   Node owner, or NULL for anonymous.
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fieldDefs
   *   Field definitions keyed by field name.
   * @param array<string, \Drupal\Core\Field\FieldItemListInterface> $fields
   *   Field item lists keyed by field name.
   *
   * @return \Drupal\node\NodeInterface&\PHPUnit\Framework\MockObject\MockObject
   *   Configured node mock.
   */
  private function buildNodeMock(
    ?UserInterface $owner,
    array $fieldDefs = [],
    array $fields = [],
  ): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('getOwner')->willReturn($owner);
    $node->method('getFieldDefinitions')->willReturn($fieldDefs);
    $node->method('get')->willReturnCallback(
      fn(string $name) => $fields[$name] ?? $this->createMock(FieldItemListInterface::class)
    );
    return $node;
  }

  /**
   * Tests that a node with author, taxonomy terms, and entity refs passes.
   *
   * @covers ::checkEntityRelationships
   */
  public function testCheckEntityRelationshipsPassesWithFullContext(): void {
    // Arrange — taxonomy field definition.
    $taxoDef = $this->createMock(FieldDefinitionInterface::class);
    $taxoDef->method('getType')->willReturn('entity_reference');
    $taxoDef->method('getSetting')->with('target_type')->willReturn('taxonomy_term');

    // Taxonomy field item list with one term.
    $term = $this->createMock(EntityInterface::class);
    $term->method('label')->willReturn('Technology');
    $taxoField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $taxoField->method('isEmpty')->willReturn(FALSE);
    $taxoField->method('referencedEntities')->willReturn([$term]);
    $taxoField->method('count')->willReturn(1);

    $node = $this->buildNodeMock(
      $this->buildAuthorMock(uid: 7, displayName: 'John Smith'),
      ['field_tags' => $taxoDef],
      ['field_tags' => $taxoField],
    );

    // Act.
    $result = $this->buildService()->checkEntityRelationships($node);

    // Assert.
    $this->assertSame('pass', $result->status);
    $this->assertSame('entity_relationships', $result->check);
    $this->assertTrue($result->details['has_real_author']);
    $this->assertNotEmpty($result->details['taxonomy_terms']);
    $this->assertGreaterThanOrEqual(1, $result->details['entity_ref_count']);
  }

  /**
   * Tests node with a named author but no taxonomy terms → 'warning'.
   *
   * @covers ::checkEntityRelationships
   */
  public function testCheckEntityRelationshipsWarnsWithPartialContext(): void {
    // Arrange — real author, but no entity reference fields at all.
    $node = $this->buildNodeMock(
      $this->buildAuthorMock(uid: 3, displayName: 'Alice'),
    // No field definitions.
      [],
      [],
    );

    // Act.
    $result = $this->buildService()->checkEntityRelationships($node);

    // Assert.
    $this->assertSame('warning', $result->status);
    $this->assertTrue($result->details['has_real_author']);
    $this->assertEmpty($result->details['taxonomy_terms']);
    $this->assertStringContainsString('Partial entity relationships', $result->description);
  }

  /**
   * Tests node with taxonomy terms but anonymous author → 'warning'.
   *
   * @covers ::checkEntityRelationships
   */
  public function testCheckEntityRelationshipsWarnsWithTaxonomyButNoRealAuthor(): void {
    // Arrange — anonymous author (uid = 0).
    $taxoDef = $this->createMock(FieldDefinitionInterface::class);
    $taxoDef->method('getType')->willReturn('entity_reference');
    $taxoDef->method('getSetting')->with('target_type')->willReturn('taxonomy_term');

    $term = $this->createMock(EntityInterface::class);
    $term->method('label')->willReturn('News');
    $taxoField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $taxoField->method('isEmpty')->willReturn(FALSE);
    $taxoField->method('referencedEntities')->willReturn([$term]);
    $taxoField->method('count')->willReturn(1);

    $anonymousOwner = $this->buildAuthorMock(uid: 0, displayName: 'Anonymous');

    $node = $this->buildNodeMock(
      $anonymousOwner,
      ['field_category' => $taxoDef],
      ['field_category' => $taxoField],
    );

    // Act.
    $result = $this->buildService()->checkEntityRelationships($node);

    // Assert — taxonomy terms present but author is anonymous → warning.
    $this->assertSame('warning', $result->status);
    $this->assertFalse($result->details['has_real_author']);
    $this->assertNotEmpty($result->details['taxonomy_terms']);
  }

  /**
   * Tests that anonymous author and no references produce 'fail'.
   *
   * @covers ::checkEntityRelationships
   */
  public function testCheckEntityRelationshipsFailsWithNoContext(): void {
    // Arrange — anonymous owner, zero fields.
    $node = $this->buildNodeMock(
      $this->buildAuthorMock(uid: 0, displayName: 'Anonymous'),
      [],
      [],
    );

    // Act.
    $result = $this->buildService()->checkEntityRelationships($node);

    // Assert.
    $this->assertSame('fail', $result->status);
    $this->assertFalse($result->details['has_real_author']);
    $this->assertEmpty($result->details['taxonomy_terms']);
    $this->assertSame(0, $result->details['entity_ref_count']);
  }

  /**
   * Tests that a null owner also triggers anonymous / fail behaviour.
   *
   * @covers ::checkEntityRelationships
   */
  public function testCheckEntityRelationshipsHandlesNullOwner(): void {
    // Arrange — getOwner() returns null.
    $node = $this->buildNodeMock(NULL, [], []);

    // Act.
    $result = $this->buildService()->checkEntityRelationships($node);

    // Assert.
    $this->assertSame('fail', $result->status);
    $this->assertSame('Unknown', $result->details['author_name']);
  }

  /**
   * Tests that non-entity-reference field types are ignored in the node check.
   *
   * @covers ::checkEntityRelationships
   */
  public function testCheckEntityRelationshipsIgnoresNonReferencedFields(): void {
    // Arrange — a text field should not affect counts.
    $textDef = $this->createMock(FieldDefinitionInterface::class);
    $textDef->method('getType')->willReturn('text_long');

    $node = $this->buildNodeMock(
      $this->buildAuthorMock(uid: 4, displayName: 'Bob'),
      ['field_body' => $textDef],
    );

    // Act.
    $result = $this->buildService()->checkEntityRelationships($node);

    // Assert — text field does not count as an entity reference.
    $this->assertSame(0, $result->details['entity_ref_count']);
  }

  /*
   * ---------------------------------------------------------------------------
   * checkEntityRelationships() — site-level (no node) tests
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that taxonomy enabled with vocabularies results in 'pass'.
   *
   * @covers ::checkEntityRelationships
   */
  public function testCheckEntityRelationshipsSiteLevelPassesWithVocabularies(): void {
    // Arrange — taxonomy module exists and has vocabularies.
    $this->moduleHandler->method('moduleExists')
      ->with('taxonomy')
      ->willReturn(TRUE);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('count')->willReturnSelf();
    $query->method('execute')->willReturn(3);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $this->entityTypeManager->method('getStorage')
      ->with('taxonomy_vocabulary')
      ->willReturn($storage);

    // Act.
    $result = $this->buildService()->checkEntityRelationships(NULL);

    // Assert.
    $this->assertSame('pass', $result->status);
    $this->assertSame(3, $result->details['vocabulary_count']);
    $this->assertTrue($result->details['taxonomy_enabled']);
  }

  /**
   * Tests that taxonomy enabled but no vocabularies configured → 'warning'.
   *
   * @covers ::checkEntityRelationships
   */
  public function testCheckEntityRelationshipsSiteLevelChecksTaxonomyNoVocabularies(): void {
    // Arrange — taxonomy module exists but vocabulary count is 0.
    $this->moduleHandler->method('moduleExists')
      ->with('taxonomy')
      ->willReturn(TRUE);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('count')->willReturnSelf();
    $query->method('execute')->willReturn(0);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $this->entityTypeManager->method('getStorage')
      ->with('taxonomy_vocabulary')
      ->willReturn($storage);

    // Act.
    $result = $this->buildService()->checkEntityRelationships(NULL);

    // Assert.
    $this->assertSame('warning', $result->status);
    $this->assertSame(0, $result->details['vocabulary_count']);
    $this->assertStringContainsString('no vocabularies', $result->description);
  }

  /**
   * Tests that taxonomy module not installed produces 'fail' at site level.
   *
   * @covers ::checkEntityRelationships
   */
  public function testCheckEntityRelationshipsSiteLevelFailsWhenTaxonomyAbsent(): void {
    // Arrange — taxonomy module not installed.
    $this->moduleHandler->method('moduleExists')
      ->with('taxonomy')
      ->willReturn(FALSE);

    // Act.
    $result = $this->buildService()->checkEntityRelationships(NULL);

    // Assert.
    $this->assertSame('fail', $result->status);
    $this->assertFalse($result->details['taxonomy_enabled']);
    $this->assertStringContainsString('Taxonomy module is not installed', $result->description);
  }

  /*
   * ---------------------------------------------------------------------------
   * checkLlmsTxt() — Sprint 1 enhanced content structure validation
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests a well-formed llms.txt file passes structure validation.
   *
   * @covers ::checkLlmsTxt
   */
  public function testCheckLlmsTxtValidStructure(): void {
    $content = implode("\n", [
      '# My Drupal Site',
      '> A comprehensive content management platform for building modern websites.',
      '',
      '## Documentation',
      '- [Getting Started](https://example.com/docs/start): How to set up your first site',
      '- [API Reference](https://example.com/docs/api): REST API documentation',
      '',
      '## Blog',
      '- [Latest Updates](https://example.com/blog): News and announcements',
    ]);

    // First call: GET /llms.txt → 200; second call: HEAD /llms-full.txt → 404.
    $this->httpClient
      ->method('request')
      ->willReturnOnConsecutiveCalls(
        $this->buildHttpResponse($content, 200),
        $this->buildHttpResponse('', 404),
      );

    $result = $this->buildService()->checkLlmsTxt();

    $this->assertSame('pass', $result->status);
    $this->assertSame('llms_txt', $result->check);
    $this->assertTrue($result->details['structure_valid']);
    $this->assertTrue($result->details['has_h1']);
    $this->assertTrue($result->details['has_blockquote']);
    $this->assertGreaterThanOrEqual(1, $result->details['h2_section_count']);
    $this->assertGreaterThanOrEqual(1, $result->details['link_count']);
    $this->assertSame([], $result->details['validation_issues']);
  }

  /**
   * Tests that content without an H1 heading produces a warning.
   *
   * @covers ::checkLlmsTxt
   */
  public function testCheckLlmsTxtMissingH1(): void {
    $content = implode("\n", [
      '## Documentation',
      '> A description without a required H1.',
      '- [Getting Started](https://example.com/docs/start): Setup guide',
    ]);

    $this->httpClient
      ->method('request')
      ->willReturnOnConsecutiveCalls(
        $this->buildHttpResponse($content, 200),
        $this->buildHttpResponse('', 404),
      );

    $result = $this->buildService()->checkLlmsTxt();

    $this->assertSame('warning', $result->status);
    $this->assertFalse($result->details['has_h1']);
    $this->assertContains('Missing required H1 heading', $result->details['validation_issues']);
  }

  /**
   * Tests that content with two H1 headings produces a warning.
   *
   * @covers ::checkLlmsTxt
   */
  public function testCheckLlmsTxtMultipleH1(): void {
    $content = implode("\n", [
      '# First Heading',
      '# Second Heading',
      '> A description.',
      '## Section',
      '- [Link](https://example.com/page): A resource',
    ]);

    $this->httpClient
      ->method('request')
      ->willReturnOnConsecutiveCalls(
        $this->buildHttpResponse($content, 200),
        $this->buildHttpResponse('', 404),
      );

    $result = $this->buildService()->checkLlmsTxt();

    $this->assertSame('warning', $result->status);
    $this->assertContains('Multiple H1 headings found', $result->details['validation_issues']);
  }

  /**
   * Tests that content with H1 but no blockquote produces a warning.
   *
   * @covers ::checkLlmsTxt
   */
  public function testCheckLlmsTxtMissingBlockquote(): void {
    $content = implode("\n", [
      '# My Site',
      '',
      '## Documentation',
      '- [Getting Started](https://example.com/docs): Setup guide',
    ]);

    $this->httpClient
      ->method('request')
      ->willReturnOnConsecutiveCalls(
        $this->buildHttpResponse($content, 200),
        $this->buildHttpResponse('', 404),
      );

    $result = $this->buildService()->checkLlmsTxt();

    $this->assertSame('warning', $result->status);
    $this->assertFalse($result->details['has_blockquote']);
    $this->assertContains('Missing required blockquote description after H1', $result->details['validation_issues']);
  }

  /**
   * Tests that a 404 for llms.txt produces a 'fail' status.
   *
   * @covers ::checkLlmsTxt
   */
  public function testCheckLlmsTxtNotFound(): void {
    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse('', 404));

    $result = $this->buildService()->checkLlmsTxt();

    $this->assertSame('fail', $result->status);
  }

  /**
   * Tests that a present /llms-full.txt companion file is detected.
   *
   * @covers ::checkLlmsTxt
   */
  public function testCheckLlmsTxtCompanionFile(): void {
    $content = implode("\n", [
      '# My Drupal Site',
      '> A comprehensive content management platform.',
      '',
      '## Documentation',
      '- [Getting Started](https://example.com/docs/start): Setup guide',
    ]);

    // GET /llms.txt → 200; HEAD /llms-full.txt → 200.
    $this->httpClient
      ->method('request')
      ->willReturnOnConsecutiveCalls(
        $this->buildHttpResponse($content, 200),
        $this->buildHttpResponse('', 200),
      );

    $result = $this->buildService()->checkLlmsTxt();

    $this->assertTrue($result->details['has_companion_file']);
  }

  /*
   * ---------------------------------------------------------------------------
   * checkSitemap() — Sprint 1 enhanced quality attributes
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that a sitemap where all URLs have <lastmod> shows 100% coverage.
   *
   * @covers ::checkSitemap
   */
  public function testCheckSitemapWithLastmod(): void {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/page1</loc><lastmod>2024-01-15</lastmod></url>
  <url><loc>https://example.com/page2</loc><lastmod>2024-02-20</lastmod></url>
  <url><loc>https://example.com/page3</loc><lastmod>2024-03-10</lastmod></url>
</urlset>
XML;

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($xml, 200));

    $result = $this->buildService()->checkSitemap();

    $this->assertSame('sitemap', $result->check);
    $this->assertSame(100.0, $result->details['lastmod_coverage_pct']);
    $this->assertSame(3, $result->details['lastmod_count']);
  }

  /**
   * Tests that a sitemap with 2 of 4 <lastmod> elements shows 50% coverage.
   *
   * @covers ::checkSitemap
   */
  public function testCheckSitemapPartialLastmod(): void {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/page1</loc><lastmod>2024-01-15</lastmod></url>
  <url><loc>https://example.com/page2</loc><lastmod>2024-02-20</lastmod></url>
  <url><loc>https://example.com/page3</loc></url>
  <url><loc>https://example.com/page4</loc></url>
</urlset>
XML;

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($xml, 200));

    $result = $this->buildService()->checkSitemap();

    $this->assertSame(50.0, $result->details['lastmod_coverage_pct']);
    $this->assertSame(2, $result->details['lastmod_count']);
  }

  /**
   * Sitemap with no lastmod elements shows 0% coverage with warning.
   *
   * @covers ::checkSitemap
   */
  public function testCheckSitemapNoLastmod(): void {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/page1</loc></url>
  <url><loc>https://example.com/page2</loc></url>
  <url><loc>https://example.com/page3</loc></url>
</urlset>
XML;

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($xml, 200));

    $result = $this->buildService()->checkSitemap();

    $this->assertSame(0.0, $result->details['lastmod_coverage_pct']);
    $this->assertSame('warning', $result->status);
    $this->assertStringContainsString('lastmod', $result->description);
  }

  /**
   * Tests that <priority> elements are tallied correctly.
   *
   * @covers ::checkSitemap
   */
  public function testCheckSitemapWithPriority(): void {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/page1</loc><lastmod>2024-01-15</lastmod><priority>0.8</priority></url>
  <url><loc>https://example.com/page2</loc><lastmod>2024-02-20</lastmod></url>
  <url><loc>https://example.com/page3</loc></url>
</urlset>
XML;

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($xml, 200));

    $result = $this->buildService()->checkSitemap();

    $this->assertSame(1, $result->details['priority_count']);
    // 1 of 3 URLs have priority → 33.3%.
    $this->assertEqualsWithDelta(33.3, $result->details['priority_coverage_pct'], 0.1);
  }

  /*
   * ---------------------------------------------------------------------------
   * checkSchemaMarkup() — Sprint 1 date properties
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that Article with datePublished and dateModified populates details.
   *
   * @covers ::checkSchemaMarkup
   */
  public function testCheckSchemaMarkupWithDateProperties(): void {
    $html = '<html><head>'
      . '<script type="application/ld+json">{"@type":"Article","headline":"Test",'
      . '"datePublished":"2024-03-15T10:00:00+00:00","dateModified":"2024-06-20T14:30:00+00:00"}</script>'
      . '<script type="application/ld+json">{"@type":"Organization","name":"ACME"}</script>'
      . '<script type="application/ld+json">{"@type":"BreadcrumbList","itemListElement":[]}</script>'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);

    $result = $this->buildService()->checkSchemaMarkup(NULL);

    $this->assertTrue($result->details['article_has_date_published']);
    $this->assertTrue($result->details['article_has_date_modified']);
    $this->assertTrue($result->details['date_published_valid_format']);
    $this->assertTrue($result->details['date_modified_valid_format']);
  }

  /**
   * Tests that an Article without date properties reports them as absent.
   *
   * @covers ::checkSchemaMarkup
   */
  public function testCheckSchemaMarkupMissingDates(): void {
    $html = '<html><head>'
      . '<script type="application/ld+json">{"@type":"Article","headline":"Test"}</script>'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);

    $result = $this->buildService()->checkSchemaMarkup(NULL);

    $this->assertFalse($result->details['article_has_date_published']);
    $this->assertFalse($result->details['article_has_date_modified']);
  }

  /*
   * ---------------------------------------------------------------------------
   * checkFeedAvailability() — Sprint 2 new check
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that a responsive /rss.xml probe produces a 'pass' result.
   *
   * @covers ::checkFeedAvailability
   */
  public function testCheckFeedAvailabilityWithRss(): void {
    // HEAD /rss.xml → 200; other HEAD probes → 404; GET homepage → HTML.
    $this->httpClient
      ->method('request')
      ->willReturnCallback(function (string $method, string $url): ResponseInterface {
        if ($method === 'HEAD' && str_ends_with($url, '/rss.xml')) {
          return $this->buildHttpResponse('', 200);
        }
        if ($method === 'HEAD') {
          return $this->buildHttpResponse('', 404);
        }
        // GET homepage — no feed link tags.
        return $this->buildHttpResponse('<html><head><title>Home</title></head><body></body></html>');
      });

    $result = $this->buildService()->checkFeedAvailability();

    $this->assertSame('pass', $result->status);
    $this->assertTrue($result->details['has_rss']);
    $this->assertGreaterThanOrEqual(1, $result->details['feed_count']);
  }

  /**
   * Tests that no feeds and no feed link tags produces an 'info' result.
   *
   * @covers ::checkFeedAvailability
   */
  public function testCheckFeedAvailabilityNone(): void {
    // All HEAD probes → 404; GET homepage → plain HTML with no feed links.
    $this->httpClient
      ->method('request')
      ->willReturnCallback(function (string $method, string $url): ResponseInterface {
        if ($method === 'HEAD') {
          return $this->buildHttpResponse('', 404);
        }
        return $this->buildHttpResponse('<html><head><title>Home</title></head><body></body></html>');
      });

    $result = $this->buildService()->checkFeedAvailability();

    $this->assertSame('info', $result->status);
    $this->assertSame(0, $result->details['feed_count']);
  }

  /**
   * Tests that an RSS <link rel="alternate"> tag in homepage HTML is detected.
   *
   * @covers ::checkFeedAvailability
   */
  public function testCheckFeedAvailabilityFromHtmlLink(): void {
    $homepageHtml = '<html><head>'
      . '<link rel="alternate" type="application/rss+xml" href="/feed" title="RSS">'
      . '</head><body></body></html>';

    // All HEAD probes → 404; GET homepage → HTML with RSS alternate link.
    $this->httpClient
      ->method('request')
      ->willReturnCallback(function (string $method, string $url) use ($homepageHtml): ResponseInterface {
        if ($method === 'HEAD') {
          return $this->buildHttpResponse('', 404);
        }
        return $this->buildHttpResponse($homepageHtml);
      });

    $result = $this->buildService()->checkFeedAvailability();

    $this->assertGreaterThanOrEqual(1, $result->details['html_link_tags_found']);
    $this->assertGreaterThanOrEqual(1, $result->details['feed_count']);
  }

  /*
   * ---------------------------------------------------------------------------
   * checkLanguageDeclaration() — Sprint 2 new check
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that <html lang="en"> produces a 'pass' result.
   *
   * @covers ::checkLanguageDeclaration
   */
  public function testCheckLanguageDeclarationPresent(): void {
    $html = '<html lang="en"><head><title>Home</title></head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $result = $this->buildService()->checkLanguageDeclaration();

    $this->assertSame('pass', $result->status);
    $this->assertTrue($result->details['has_lang_attribute']);
    $this->assertSame('en', $result->details['lang_value']);
  }

  /**
   * Tests that <html> without a lang attribute produces a 'fail' result.
   *
   * @covers ::checkLanguageDeclaration
   */
  public function testCheckLanguageDeclarationMissing(): void {
    $html = '<html><head><title>Home</title></head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $result = $this->buildService()->checkLanguageDeclaration();

    $this->assertSame('fail', $result->status);
    $this->assertFalse($result->details['has_lang_attribute']);
  }

  /**
   * Tests that hreflang tags are counted in the language declaration details.
   *
   * @covers ::checkLanguageDeclaration
   */
  public function testCheckLanguageDeclarationWithHreflang(): void {
    $html = '<html lang="en"><head>'
      . '<link rel="alternate" hreflang="fr" href="https://example.com/fr/">'
      . '<link rel="alternate" hreflang="de" href="https://example.com/de/">'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $result = $this->buildService()->checkLanguageDeclaration();

    $this->assertSame('pass', $result->status);
    $this->assertGreaterThanOrEqual(1, $result->details['hreflang_count']);
  }

  /*
   * ---------------------------------------------------------------------------
   * checkJsonApi() — Sprint 2 new check
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that an installed JSON:API module with accessible endpoint passes.
   *
   * @covers ::checkJsonApi
   */
  public function testCheckJsonApiEnabled(): void {
    $this->moduleHandler
      ->method('moduleExists')
      ->with('jsonapi')
      ->willReturn(TRUE);

    // HEAD /jsonapi → 200.
    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse('', 200));

    $result = $this->buildService()->checkJsonApi();

    $this->assertSame('pass', $result->status);
    $this->assertTrue($result->details['module_installed']);
    $this->assertTrue($result->details['endpoint_accessible']);
  }

  /**
   * Tests that a missing JSON:API module produces an 'info' result.
   *
   * @covers ::checkJsonApi
   */
  public function testCheckJsonApiNotInstalled(): void {
    $this->moduleHandler
      ->method('moduleExists')
      ->with('jsonapi')
      ->willReturn(FALSE);

    $result = $this->buildService()->checkJsonApi();

    $this->assertSame('info', $result->status);
    $this->assertFalse($result->details['module_installed']);
  }

  /*
   * ---------------------------------------------------------------------------
   * checkContentLicensing() — Sprint 2 new check
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that a <link rel="license"> tag produces a 'pass' result.
   *
   * @covers ::checkContentLicensing
   */
  public function testCheckContentLicensingWithLicenseLink(): void {
    $licenseHref = 'https://creativecommons.org/licenses/by/4.0/';
    $html = '<html><head>'
      . '<link rel="license" href="' . $licenseHref . '">'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $result = $this->buildService()->checkContentLicensing();

    $this->assertSame('pass', $result->status);
    $this->assertTrue($result->details['has_license_link']);
    $this->assertSame($licenseHref, $result->details['license_url']);
  }

  /**
   * Tests that HTML with no licensing signals produces an 'info' result.
   *
   * @covers ::checkContentLicensing
   */
  public function testCheckContentLicensingNone(): void {
    $html = '<html><head><title>Home</title></head><body><p>Content.</p></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $result = $this->buildService()->checkContentLicensing();

    $this->assertSame('info', $result->status);
  }

  /**
   * Tests that a JSON-LD "license" property is detected.
   *
   * @covers ::checkContentLicensing
   */
  public function testCheckContentLicensingWithSchemaLicense(): void {
    $html = '<html><head>'
      . '<script type="application/ld+json">{"@type":"WebPage","name":"Home",'
      . '"license":"https://creativecommons.org/licenses/by/4.0/"}</script>'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $result = $this->buildService()->checkContentLicensing();

    $this->assertTrue($result->details['has_schema_license']);
    $this->assertSame('pass', $result->status);
  }

  /*
   * ---------------------------------------------------------------------------
   * checkDateMetaTags() — Sprint 2 new check
   * ---------------------------------------------------------------------------
   */

  /**
   * Tests that both OG date meta tags present and valid produce a 'pass'.
   *
   * @covers ::checkDateMetaTags
   */
  public function testCheckDateMetaTagsBothPresent(): void {
    $html = '<html><head>'
      . '<meta property="article:published_time" content="2024-03-15T10:00:00+00:00">'
      . '<meta property="article:modified_time" content="2024-06-20T14:30:00+00:00">'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $result = $this->buildService()->checkDateMetaTags(NULL);

    $this->assertSame('pass', $result->status);
    $this->assertTrue($result->details['has_published_time']);
    $this->assertTrue($result->details['has_modified_time']);
    $this->assertTrue($result->details['published_time_valid']);
    $this->assertTrue($result->details['modified_time_valid']);
  }

  /**
   * Tests that only article:published_time present produces a 'warning'.
   *
   * @covers ::checkDateMetaTags
   */
  public function testCheckDateMetaTagsOnlyPublished(): void {
    $html = '<html><head>'
      . '<meta property="article:published_time" content="2024-03-15T10:00:00+00:00">'
      . '</head><body></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $result = $this->buildService()->checkDateMetaTags(NULL);

    $this->assertSame('warning', $result->status);
    $this->assertTrue($result->details['has_published_time']);
    $this->assertFalse($result->details['has_modified_time']);
  }

  /**
   * Tests that no date meta tags at all produces an 'info' result.
   *
   * @covers ::checkDateMetaTags
   */
  public function testCheckDateMetaTagsNone(): void {
    $html = '<html><head><title>Home</title></head><body><p>Content.</p></body></html>';

    $this->httpClient
      ->method('request')
      ->willReturn($this->buildHttpResponse($html));

    $result = $this->buildService()->checkDateMetaTags(NULL);

    $this->assertSame('info', $result->status);
    $this->assertFalse($result->details['has_published_time']);
    $this->assertFalse($result->details['has_modified_time']);
  }

}
