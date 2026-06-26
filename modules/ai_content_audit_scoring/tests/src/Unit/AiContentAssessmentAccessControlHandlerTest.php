<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit_scoring\Unit;

use Drupal\ai_content_audit_scoring\AiContentAssessmentAccessControlHandler;
use Drupal\ai_content_audit_scoring\Entity\AiContentAssessment;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for AiContentAssessmentAccessControlHandler.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit_scoring\AiContentAssessmentAccessControlHandler
 */
class AiContentAssessmentAccessControlHandlerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('cache_tags.invalidator', $this->createMock(CacheTagsInvalidatorInterface::class));
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a handler instance with a minimal EntityTypeInterface mock.
   */
  protected function buildHandler(): AiContentAssessmentAccessControlHandler {
    $entityType = $this->createMock(EntityTypeInterface::class);
    return new AiContentAssessmentAccessControlHandler($entityType);
  }

  /**
   * Builds an assessment entity mock with optional node view access.
   */
  protected function buildAssessmentEntity(bool $nodeViewAllowed = TRUE): AiContentAssessment {
    $node = $this->createMock(NodeInterface::class);
    $node->method('access')->willReturnCallback(
      static function (string $operation, $account, bool $returnAsObject = FALSE) use ($nodeViewAllowed) {
        $result = $nodeViewAllowed ? AccessResult::allowed() : AccessResult::forbidden();
        return $returnAsObject ? $result : $nodeViewAllowed;
      }
    );

    $entity = $this->createMock(AiContentAssessment::class);
    $entity->method('getTargetNode')->willReturn($node);
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheTags')->willReturn([]);
    $entity->method('getCacheMaxAge')->willReturn(-1);
    return $entity;
  }

  /**
   * Invokes the protected checkAccess() method via reflection.
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
    $entity = $this->buildAssessmentEntity(TRUE);

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
    $entity = $this->buildAssessmentEntity(TRUE);

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
    $entity = $this->createMock(EntityInterface::class);

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
