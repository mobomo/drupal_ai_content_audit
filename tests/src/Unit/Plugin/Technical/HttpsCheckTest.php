<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\Plugin\Technical;

use Drupal\ai_content_audit\Plugin\AuditCheck\Technical\HttpsCheck;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for the HttpsCheck audit-check plugin.
 *
 * HttpsCheck is the simplest technical check: it inspects only the current
 * RequestStack to determine whether the site is served over HTTPS.  No HTTP
 * client or other services are required, making it an ideal candidate for a
 * straightforward unit test.
 *
 * Key implementation details confirmed before writing:
 *  - HTTPS request  → status 'pass'
 *  - HTTP request   → status 'warning'   (NOT 'fail' — matches actual code)
 *  - scope is 'site' → applies() returns TRUE for both NULL and a NodeInterface
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit\Plugin\AuditCheck\Technical\HttpsCheck
 */
class HttpsCheckTest extends TestCase {

  // ---------------------------------------------------------------------------
  // Helper
  // ---------------------------------------------------------------------------

  /**
   * Plugin definition array that mirrors the #[AuditCheck] attribute values.
   *
   * TranslatableMarkup is replaced with plain strings for unit-test simplicity —
   * AuditCheckBase::getLabel() casts the value to string regardless.
   *
   * @var array<string, mixed>
   */
  private const PLUGIN_DEFINITION = [
    'id'          => 'https',
    'label'       => 'HTTPS',
    'description' => 'Verifies the site is served over HTTPS.',
    'scope'       => 'site',
    'category'    => 'Technical',
  ];

  /**
   * Builds an HttpsCheck plugin instance whose RequestStack reports the given
   * security state.
   *
   * @param bool $isSecure
   *   Whether the mock request should report HTTPS (TRUE) or HTTP (FALSE).
   * @param bool $noRequest
   *   When TRUE, RequestStack::getCurrentRequest() returns NULL (CLI context).
   */
  private function buildCheck(bool $isSecure = TRUE, bool $noRequest = FALSE): HttpsCheck {
    $request = $this->createMock(Request::class);
    $request->method('isSecure')->willReturn($isSecure);

    $requestStack = $this->createMock(RequestStack::class);
    $requestStack
      ->method('getCurrentRequest')
      ->willReturn($noRequest ? NULL : $request);

    return new HttpsCheck(
      [],
      'https',
      self::PLUGIN_DEFINITION,
      $requestStack,
    );
  }

  // ---------------------------------------------------------------------------
  // run() — HTTPS context
  // ---------------------------------------------------------------------------

  /**
   * Run() returns a 'pass' result when the current request is HTTPS.
   *
   * @covers ::run
   */
  public function testRunReturnsPassWhenRequestIsHttps(): void {
    $result = $this->buildCheck(isSecure: TRUE)->run(NULL);

    $this->assertInstanceOf(TechnicalAuditResult::class, $result);
    $this->assertSame('pass', $result->status);
    $this->assertSame('https', $result->check);
  }

  /**
   * Run() 'pass' result description mentions HTTPS.
   *
   * @covers ::run
   */
  public function testRunPassDescriptionMentionsHttps(): void {
    $result = $this->buildCheck(isSecure: TRUE)->run(NULL);

    $this->assertStringContainsString('HTTPS', $result->description);
  }

  /**
   * Run() result label is derived from the plugin definition.
   *
   * @covers ::run
   */
  public function testRunResultUsesPluginDefinitionLabel(): void {
    $result = $this->buildCheck(isSecure: TRUE)->run(NULL);

    $this->assertSame('HTTPS', $result->label);
  }

  // ---------------------------------------------------------------------------
  // run() — HTTP context
  // ---------------------------------------------------------------------------

  /**
   * Run() returns a 'warning' result (not 'fail') when the request is HTTP.
   *
   * The current implementation degrades gracefully to a warning rather than a
   * hard failure so that development environments are not blocked.
   *
   * @covers ::run
   */
  public function testRunReturnsWarningWhenRequestIsHttp(): void {
    $result = $this->buildCheck(isSecure: FALSE)->run(NULL);

    $this->assertInstanceOf(TechnicalAuditResult::class, $result);
    $this->assertSame('warning', $result->status);
    $this->assertSame('https', $result->check);
  }

  /**
   * Run() 'warning' description mentions HTTPS as a recommendation.
   *
   * @covers ::run
   */
  public function testRunWarningDescriptionMentionsHttps(): void {
    $result = $this->buildCheck(isSecure: FALSE)->run(NULL);

    $this->assertStringContainsString('HTTPS', $result->description);
  }

  /**
   * Run() returns 'warning' when no request is available (CLI / Drush context).
   *
   * @covers ::run
   */
  public function testRunReturnsWarningWhenNoRequestAvailable(): void {
    $result = $this->buildCheck(isSecure: FALSE, noRequest: TRUE)->run(NULL);

    // No request → isSecure defaults to FALSE inside HttpsCheck → warning.
    $this->assertSame('warning', $result->status);
  }

  // ---------------------------------------------------------------------------
  // run() called with a node argument
  // ---------------------------------------------------------------------------

  /**
   * Run() with a NodeInterface still returns the correct result (site-scoped).
   *
   * @covers ::run
   */
  public function testRunWithNodeReturnsPassForHttpsRequest(): void {
    $node   = $this->createMock(NodeInterface::class);
    $result = $this->buildCheck(isSecure: TRUE)->run($node);

    $this->assertSame('pass', $result->status);
  }

  // ---------------------------------------------------------------------------
  // applies() — inherited from AuditCheckBase (scope: 'site')
  // ---------------------------------------------------------------------------

  /**
   * Applies(NULL) returns TRUE — site-scoped checks always apply.
   *
   * @covers \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase::applies
   */
  public function testAppliesReturnsTrueForNullNode(): void {
    $this->assertTrue($this->buildCheck()->applies(NULL));
  }

  /**
   * Applies($node) returns TRUE — site-scoped checks apply in node contexts too.
   *
   * @covers \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase::applies
   */
  public function testAppliesReturnsTrueWhenNodeProvided(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->assertTrue($this->buildCheck()->applies($node));
  }

  // ---------------------------------------------------------------------------
  // Plugin metadata helpers (inherited from AuditCheckBase)
  // ---------------------------------------------------------------------------

  /**
   * GetId() returns the plugin ID from the definition.
   *
   * @covers \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase::getId
   */
  public function testGetIdReturnsHttps(): void {
    $this->assertSame('https', $this->buildCheck()->getId());
  }

  /**
   * GetLabel() returns the plugin label from the definition.
   *
   * @covers \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase::getLabel
   */
  public function testGetLabelReturnsHttps(): void {
    $this->assertSame('HTTPS', $this->buildCheck()->getLabel());
  }

  /**
   * GetCategory() returns the plugin category from the definition.
   *
   * @covers \Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase::getCategory
   */
  public function testGetCategoryReturnsTechnical(): void {
    $this->assertSame('Technical', $this->buildCheck()->getCategory());
  }

}
