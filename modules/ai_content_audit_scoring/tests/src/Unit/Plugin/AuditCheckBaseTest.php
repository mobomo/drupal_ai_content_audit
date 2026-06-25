<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit_scoring\Unit\Plugin;

use Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckBase;
use Drupal\ai_content_audit_scoring\ValueObject\TechnicalAuditResult;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuditCheckBase.
 *
 * Because AuditCheckBase is abstract, the tests instantiate minimal anonymous
 * concrete subclasses directly (no plugin manager needed).  Protected helper
 * methods (pass, fail, warning) are exposed through thin wrappers defined on
 * those anonymous classes.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckBase
 */
class AuditCheckBaseTest extends TestCase {

  /*
   * ---------------------------------------------------------------------------
   * Helper: anonymous concrete subclass factory
   * ---------------------------------------------------------------------------
   */

  /**
   * Creates an anonymous AuditCheckBase subclass for the given scope.
   *
   * The returned object is wired with a minimal plugin definition so that
   * getId(), getLabel(), getCategory(), and applies() work as expected.
   * Public callPass/callFail/callWarning wrappers exercise protected helpers.
   *
   * @param string $scope
   *   Plugin scope — 'site' or 'node'.
   * @param string $id
   *   Plugin ID.
   * @param string $label
   *   Plugin label.
   * @param string $category
   *   Plugin category.
   *
   * @return \Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckBase&\Drupal\Tests\ai_content_audit_scoring\Unit\Plugin\TestAuditCheckInterface
   *   A concrete audit check with public wrappers for protected helpers.
   */
  private function makeCheck(
    string $scope = 'site',
    string $id = 'test_check',
    string $label = 'Test Check',
    string $category = 'General',
  ): AuditCheckBase {
    return new class(
      ['scope' => $scope],
      $id,
      ['id' => $id, 'label' => $label, 'category' => $category, 'scope' => $scope],
    ) extends AuditCheckBase implements TestAuditCheckInterface {

      /**
       * {@inheritdoc}
       */
      public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
        return $this->pass('run() called', 'current', 'recommended');
      }

      /**
       * Thin wrappers that promote the protected factory methods to public.
       */
      public function callPass(string $desc, ?string $current = NULL, ?string $recommended = NULL, array $details = []): TechnicalAuditResult {
        return $this->pass($desc, $current, $recommended, $details);
      }

      /**
       * Exposes fail() for unit tests.
       */
      public function callFail(string $desc, ?string $current = NULL, ?string $recommended = NULL, array $details = []): TechnicalAuditResult {
        return $this->fail($desc, $current, $recommended, $details);
      }

      /**
       * Exposes warning() for unit tests.
       */
      public function callWarning(string $desc, ?string $current = NULL, ?string $recommended = NULL, array $details = []): TechnicalAuditResult {
        return $this->warning($desc, $current, $recommended, $details);
      }

    };
  }

  /*
   * ---------------------------------------------------------------------------
   * getId() / getLabel() / getCategory()
   * ---------------------------------------------------------------------------
   */

  /**
   * GetId() returns the 'id' value from the plugin definition.
   *
   * @covers ::getId
   */
  public function testGetIdReturnsPluginDefinitionId(): void {
    $this->assertSame('test_check', $this->makeCheck(id: 'test_check')->getId());
  }

  /**
   * GetId() reflects a different ID when one is provided.
   *
   * @covers ::getId
   */
  public function testGetIdReturnsCustomId(): void {
    $this->assertSame('my_custom_check', $this->makeCheck(id: 'my_custom_check')->getId());
  }

  /**
   * GetLabel() returns the 'label' value from the plugin definition.
   *
   * @covers ::getLabel
   */
  public function testGetLabelReturnsPluginDefinitionLabel(): void {
    $this->assertSame('Test Check', $this->makeCheck(label: 'Test Check')->getLabel());
  }

  /**
   * GetCategory() returns the 'category' value from the plugin definition.
   *
   * @covers ::getCategory
   */
  public function testGetCategoryReturnsPluginDefinitionCategory(): void {
    $this->assertSame('General', $this->makeCheck(category: 'General')->getCategory());
  }

  /**
   * GetCategory() returns a category other than 'General' when specified.
   *
   * @covers ::getCategory
   */
  public function testGetCategoryReturnsCustomCategory(): void {
    $this->assertSame('Technical', $this->makeCheck(category: 'Technical')->getCategory());
  }

  /*
   * ---------------------------------------------------------------------------
   * applies()
   * ---------------------------------------------------------------------------
   */

  /**
   * Applies(NULL) returns TRUE for a 'site'-scoped check.
   *
   * @covers ::applies
   */
  public function testAppliesReturnsTrueForSiteScopeWithNullNode(): void {
    $this->assertTrue($this->makeCheck(scope: 'site')->applies(NULL));
  }

  /**
   * Applies(NULL) returns FALSE for a 'node'-scoped check (no node provided).
   *
   * @covers ::applies
   */
  public function testAppliesReturnsFalseForNodeScopeWithNullNode(): void {
    $this->assertFalse($this->makeCheck(scope: 'node')->applies(NULL));
  }

  /**
   * Applies($node) returns TRUE for a 'node'-scoped check when a node is given.
   *
   * @covers ::applies
   */
  public function testAppliesReturnsTrueForNodeScopeWithNode(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->assertTrue($this->makeCheck(scope: 'node')->applies($node));
  }

  /**
   * Applies($node) returns TRUE for a 'site'-scoped check when a node is given.
   *
   * Site-scoped checks apply in both node and non-node contexts.
   *
   * @covers ::applies
   */
  public function testAppliesReturnsTrueForSiteScopeWithNode(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->assertTrue($this->makeCheck(scope: 'site')->applies($node));
  }

  /*
   * ---------------------------------------------------------------------------
   * pass() / fail() / warning() result factory helpers
   * ---------------------------------------------------------------------------
   */

  /**
   * Pass() returns TechnicalAuditResult with status pass and correct fields.
   *
   * @covers \Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckBase::pass
   */
  public function testPassReturnsResultWithPassStatus(): void {
    $check  = $this->makeCheck();
    $result = $check->callPass(
      'Everything OK',
      'current value',
      'recommended value',
      ['key' => 'val'],
    );

    $this->assertInstanceOf(TechnicalAuditResult::class, $result);
    $this->assertSame('pass', $result->status);
    $this->assertSame('test_check', $result->check);
    $this->assertSame('Test Check', $result->label);
    $this->assertSame('Everything OK', $result->description);
    $this->assertSame('current value', $result->currentContent);
    $this->assertSame('recommended value', $result->recommendedContent);
    $this->assertSame(['key' => 'val'], $result->details);
  }

  /**
   * Pass() with no optional arguments still produces a valid result.
   *
   * @covers \Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckBase::pass
   */
  public function testPassDefaultsCurrentAndRecommendedToNull(): void {
    $result = $this->makeCheck()->callPass('OK');

    $this->assertSame('pass', $result->status);
    $this->assertNull($result->currentContent);
    $this->assertNull($result->recommendedContent);
    $this->assertSame([], $result->details);
  }

  /**
   * Fail() returns a TechnicalAuditResult with status 'fail'.
   *
   * @covers \Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckBase::fail
   */
  public function testFailReturnsResultWithFailStatus(): void {
    $check  = $this->makeCheck();
    $result = $check->callFail('Something broke', 'current', 'recommended', ['detail' => 1]);

    $this->assertInstanceOf(TechnicalAuditResult::class, $result);
    $this->assertSame('fail', $result->status);
    $this->assertSame('test_check', $result->check);
    $this->assertSame('Test Check', $result->label);
    $this->assertSame('Something broke', $result->description);
    $this->assertSame('current', $result->currentContent);
    $this->assertSame('recommended', $result->recommendedContent);
    $this->assertSame(['detail' => 1], $result->details);
  }

  /**
   * Warning() returns a TechnicalAuditResult with status 'warning'.
   *
   * @covers \Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckBase::warning
   */
  public function testWarningReturnsResultWithWarningStatus(): void {
    $check  = $this->makeCheck();
    $result = $check->callWarning('Needs attention', NULL, NULL, ['warn' => TRUE]);

    $this->assertInstanceOf(TechnicalAuditResult::class, $result);
    $this->assertSame('warning', $result->status);
    $this->assertSame('test_check', $result->check);
    $this->assertSame('Needs attention', $result->description);
    $this->assertNull($result->currentContent);
    $this->assertNull($result->recommendedContent);
    $this->assertSame(['warn' => TRUE], $result->details);
  }

  /**
   * Run() on the anonymous concrete subclass returns a TechnicalAuditResult.
   *
   * Validates end-to-end that the concrete run() implementation can use the
   * pass() helper correctly.
   *
   * @covers \Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckBase::pass
   */
  public function testRunReturnsTechnicalAuditResult(): void {
    $check  = $this->makeCheck();
    $result = $check->run(NULL);

    $this->assertInstanceOf(TechnicalAuditResult::class, $result);
    $this->assertSame('pass', $result->status);
    $this->assertSame('test_check', $result->check);
  }

}

/**
 * Describes public wrappers exposed by the anonymous test check.
 */
interface TestAuditCheckInterface {

  /**
   * Exposes AuditCheckBase::pass().
   */
  public function callPass(
    string $desc,
    ?string $current = NULL,
    ?string $recommended = NULL,
    array $details = [],
  ): TechnicalAuditResult;

  /**
   * Exposes AuditCheckBase::fail().
   */
  public function callFail(
    string $desc,
    ?string $current = NULL,
    ?string $recommended = NULL,
    array $details = [],
  ): TechnicalAuditResult;

  /**
   * Exposes AuditCheckBase::warning().
   */
  public function callWarning(
    string $desc,
    ?string $current = NULL,
    ?string $recommended = NULL,
    array $details = [],
  ): TechnicalAuditResult;

}
