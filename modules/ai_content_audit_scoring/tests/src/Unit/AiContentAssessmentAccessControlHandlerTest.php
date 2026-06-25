<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit_scoring\Unit;

use Drupal\ai_content_audit_scoring\AiContentAssessmentAccessControlHandler;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AiContentAssessmentAccessControlHandler.
 *
 * Because checkAccess() is a protected method we invoke it via reflection so
 * we do not need the full Drupal entity system bootstrapped.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit_scoring\AiContentAssessmentAccessControlHandler
 */
class AiContentAssessmentAccessControlHandlerTest extends TestCase {

  /**
   * Builds a handler instance with a minimal EntityTypeInterface mock.
   */
  protected function buildHandler(): AiContentAssessmentAccessControlHandler {
    $entityType = $this->createMock(EntityTypeInterface::class);
    return new AiContentAssessmentAccessControlHandler($entityType);
  }

  /**
   * Invokes the protected checkAccess() method via reflection.
   *
   * @param \Drupal\ai_content_audit_scoring\AiContentAssessmentAccessControlHandler $handler
   *   The handler under test.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A mock entity (not used by this handler's logic).
   * @param string $operation
   *   The operation string ('view', 'delete', 'update', …).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   A mock account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result returned by checkAccess().
   */
  protected function invokeCheckAccess(
    AiContentAssessmentAccessControlHandler $handler,
    EntityInterface $entity,
    string $operation,
    AccountInterface $account,
  ): AccessResultInterface {
    $reflection = new \ReflectionMethod($handler, 'checkAccess');
    $reflection->setAccessible(TRUE);
    return $reflection->invoke($handler, $entity, $operation, $account);
  }

  /**
   * Tests view access is granted when account holds the view permission.
   *
   * @covers ::checkAccess
   */
  public function testViewAccessGrantedWithPermission(): void {
    $handler = $this->buildHandler();
    $entity  = $this->createMock(EntityInterface::class);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnMap([
      ['view ai content assessment', TRUE],
      ['administer ai content audit', FALSE],
    ]);

    $result = $this->invokeCheckAccess($handler, $entity, 'view', $account);

    $this->assertInstanceOf(AccessResultInterface::class, $result);
    $this->assertTrue($result->isAllowed(), 'View access should be allowed with the "view ai content assessment" permission.');
  }

  /**
   * Tests view access is denied when the account lacks the view permission.
   *
   * @covers ::checkAccess
   */
  public function testViewAccessDeniedWithoutPermission(): void {
    $handler = $this->buildHandler();
    $entity  = $this->createMock(EntityInterface::class);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $result = $this->invokeCheckAccess($handler, $entity, 'view', $account);

    $this->assertInstanceOf(AccessResultInterface::class, $result);
    $this->assertFalse($result->isAllowed(), 'View access must not be allowed without the required permission.');
  }

  /**
   * Tests delete access is granted for an administrator account.
   *
   * @covers ::checkAccess
   */
  public function testDeleteAccessGrantedForAdmin(): void {
    $handler = $this->buildHandler();
    $entity  = $this->createMock(EntityInterface::class);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnMap([
      ['administer ai content audit', TRUE],
      ['view ai content assessment', FALSE],
    ]);

    $result = $this->invokeCheckAccess($handler, $entity, 'delete', $account);

    $this->assertInstanceOf(AccessResultInterface::class, $result);
    $this->assertTrue($result->isAllowed(), 'Delete access should be allowed for an account with "administer ai content audit".');
  }

}
