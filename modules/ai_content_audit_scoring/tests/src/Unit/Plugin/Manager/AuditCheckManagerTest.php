<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit_scoring\Unit\Plugin\Manager;

use Drupal\ai_content_audit_scoring\Plugin\AuditCheck\AuditCheckInterface;
use Drupal\ai_content_audit_scoring\Plugin\Manager\AuditCheckManager;
use Drupal\ai_content_audit_scoring\ValueObject\TechnicalAuditResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuditCheckManager.
 *
 * Because AuditCheckManager extends DefaultPluginManager (which requires heavy
 * DI for full instantiation), tests use a partial mock with
 * disableOriginalConstructor() and inject the configFactory property via
 * reflection.  getDefinitions() and createInstance() are also mocked so that
 * the real runAll() / getEnabledCheckIds() logic can be exercised without a
 * booted Drupal container.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit_scoring\Plugin\Manager\AuditCheckManager
 */
class AuditCheckManagerTest extends TestCase {

  /*
   * ---------------------------------------------------------------------------
   * Helpers
   * ---------------------------------------------------------------------------
   */

  /**
   * Builds a partial AuditCheckManager mock with controlled definitions.
   *
   * @param string[] $disabled
   *   IDs listed in the 'disabled_checks' config array.
   * @param array<string, array<string, mixed>> $definitions
   *   Plugin definitions keyed by plugin ID (returned by getDefinitions()).
   *
   * @return \Drupal\ai_content_audit_scoring\Plugin\Manager\AuditCheckManager&\PHPUnit\Framework\MockObject\MockObject
   *   Partial manager mock.
   */
  private function buildManager(array $disabled = [], array $definitions = []): AuditCheckManager {
    // Config double: 'disabled_checks' returns the provided array.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('disabled_checks')->willReturn($disabled);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ai_content_audit_scoring.settings')
      ->willReturn($config);

    /** @var \Drupal\ai_content_audit_scoring\Plugin\Manager\AuditCheckManager&\PHPUnit\Framework\MockObject\MockObject $manager */
    $manager = $this->getMockBuilder(AuditCheckManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getDefinitions', 'createInstance'])
      ->getMock();

    $manager->method('getDefinitions')->willReturn($definitions);

    // Inject the constructor-promoted $configFactory via reflection because the
    // original constructor was disabled.
    $ref = new \ReflectionProperty(AuditCheckManager::class, 'configFactory');
    $ref->setAccessible(TRUE);
    $ref->setValue($manager, $configFactory);

    return $manager;
  }

  /*
   * ---------------------------------------------------------------------------
   * getEnabledCheckIds() tests
   * ---------------------------------------------------------------------------
   */

  /**
   * All check IDs are returned when disabled_checks is empty.
   *
   * @covers ::getEnabledCheckIds
   */
  public function testGetEnabledCheckIdsReturnsAllWhenNoneDisabled(): void {
    $definitions = [
      'robots_txt' => ['id' => 'robots_txt'],
      'https'      => ['id' => 'https'],
      'sitemap'    => ['id' => 'sitemap'],
    ];
    $manager = $this->buildManager([], $definitions);

    $ids = $manager->getEnabledCheckIds();

    $this->assertCount(3, $ids);
    $this->assertContains('robots_txt', $ids);
    $this->assertContains('https', $ids);
    $this->assertContains('sitemap', $ids);
  }

  /**
   * A disabled ID is excluded from the returned list.
   *
   * @covers ::getEnabledCheckIds
   */
  public function testGetEnabledCheckIdsExcludesDisabledCheckIds(): void {
    $definitions = [
      'robots_txt' => ['id' => 'robots_txt'],
      'https'      => ['id' => 'https'],
      'sitemap'    => ['id' => 'sitemap'],
    ];
    $manager = $this->buildManager(['robots_txt'], $definitions);

    $ids = $manager->getEnabledCheckIds();

    $this->assertCount(2, $ids);
    $this->assertNotContains('robots_txt', $ids);
    $this->assertContains('https', $ids);
    $this->assertContains('sitemap', $ids);
  }

  /**
   * Multiple disabled IDs are all excluded.
   *
   * @covers ::getEnabledCheckIds
   */
  public function testGetEnabledCheckIdsExcludesMultipleDisabledIds(): void {
    $definitions = [
      'robots_txt' => ['id' => 'robots_txt'],
      'https'      => ['id' => 'https'],
      'sitemap'    => ['id' => 'sitemap'],
      'llms_txt'   => ['id' => 'llms_txt'],
    ];
    $manager = $this->buildManager(['robots_txt', 'llms_txt'], $definitions);

    $ids = $manager->getEnabledCheckIds();

    $this->assertCount(2, $ids);
    $this->assertNotContains('robots_txt', $ids);
    $this->assertNotContains('llms_txt', $ids);
  }

  /*
   * ---------------------------------------------------------------------------
   * runAll() tests
   * ---------------------------------------------------------------------------
   */

  /**
   * RunAll() returns an empty array when no checks are registered.
   *
   * @covers ::runAll
   */
  public function testRunAllReturnsEmptyArrayWhenNoChecksRegistered(): void {
    $manager = $this->buildManager([], []);

    $results = $manager->runAll(NULL);

    $this->assertSame([], $results);
  }

  /**
   * RunAll() skips checks whose applies() returns FALSE.
   *
   * @covers ::runAll
   */
  public function testRunAllSkipsCheckWhenAppliesReturnsFalse(): void {
    $definitions = ['node_check' => ['id' => 'node_check']];
    $manager = $this->buildManager([], $definitions);

    $check = $this->createMock(AuditCheckInterface::class);
    $check->method('applies')->willReturn(FALSE);
    $check->expects($this->never())->method('run');
    $manager->method('createInstance')->with('node_check')->willReturn($check);

    $node    = $this->createMock(NodeInterface::class);
    $results = $manager->runAll($node);

    $this->assertArrayNotHasKey('node_check', $results);
    $this->assertEmpty($results);
  }

  /**
   * RunAll() records a 'fail' TechnicalAuditResult when a check throws.
   *
   * @covers ::runAll
   */
  public function testRunAllRecordsFailResultWhenCheckThrowsException(): void {
    $definitions = ['bad_check' => ['id' => 'bad_check']];
    $manager     = $this->buildManager([], $definitions);

    $check = $this->createMock(AuditCheckInterface::class);
    $check->method('applies')->willReturn(TRUE);
    $check->method('run')->willThrowException(new \RuntimeException('Something exploded'));
    $manager->method('createInstance')->with('bad_check')->willReturn($check);

    $results = $manager->runAll(NULL);

    $this->assertArrayHasKey('bad_check', $results);
    $this->assertInstanceOf(TechnicalAuditResult::class, $results['bad_check']);
    $this->assertSame('fail', $results['bad_check']->status);
    $this->assertStringContainsString('Something exploded', $results['bad_check']->description);
  }

  /**
   * RunAll() includes the result returned by a passing check.
   *
   * @covers ::runAll
   */
  public function testRunAllIncludesResultFromPassingCheck(): void {
    $definitions = ['good_check' => ['id' => 'good_check']];
    $manager     = $this->buildManager([], $definitions);

    $expected = new TechnicalAuditResult(
      check: 'good_check',
      label: 'Good Check',
      status: 'pass',
      currentContent: NULL,
      recommendedContent: NULL,
      description: 'All is well.',
    );

    $check = $this->createMock(AuditCheckInterface::class);
    $check->method('applies')->willReturn(TRUE);
    $check->method('run')->willReturn($expected);
    $manager->method('createInstance')->with('good_check')->willReturn($check);

    $results = $manager->runAll(NULL);

    $this->assertArrayHasKey('good_check', $results);
    $this->assertSame($expected, $results['good_check']);
  }

  /**
   * RunAll() passes NodeInterface through to each check's applies()/run().
   *
   * @covers ::runAll
   */
  public function testRunAllPassesNodeToAppliesAndRun(): void {
    $definitions = ['node_check' => ['id' => 'node_check']];
    $manager     = $this->buildManager([], $definitions);

    $node = $this->createMock(NodeInterface::class);

    $result = new TechnicalAuditResult(
      check: 'node_check',
      label: 'Node Check',
      status: 'pass',
      currentContent: NULL,
      recommendedContent: NULL,
      description: 'Node check passed.',
    );

    $check = $this->createMock(AuditCheckInterface::class);
    $check->expects($this->once())->method('applies')->with($node)->willReturn(TRUE);
    $check->expects($this->once())->method('run')->with($node)->willReturn($result);
    $manager->method('createInstance')->with('node_check')->willReturn($check);

    $results = $manager->runAll($node);

    $this->assertArrayHasKey('node_check', $results);
  }

}
