<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit;

use Drupal\ai_content_audit\Entity\AiContentAssessment;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for AI Content Assessment entities.
 *
 * Permission map:
 *   - 'view ai content assessment'  → editors can read assessments
 *   - 'run ai content assessment'   → editors can trigger assessments
 *   - 'administer ai content audit' → admins have full control.
 */
class AiContentAssessmentAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $admin = AccessResult::allowedIfHasPermission($account, 'administer ai content audit')
      ->cachePerPermissions();
    $view_assessment = $admin->orIf(
      AccessResult::allowedIfHasPermission($account, 'view ai content assessment')
        ->cachePerPermissions()
    );

    return match ($operation) {
      'view'   => $view_assessment->andIf($this->targetNodeViewAccess($entity, $account)),
      'delete' => $admin,
      'update' => $admin,
      default  => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'run ai content assessment')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'administer ai content audit'))
      ->cachePerPermissions();
  }

  /**
   * Checks access to the node an assessment belongs to.
   */
  private function targetNodeViewAccess(EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof AiContentAssessment) {
      return AccessResult::forbidden()
        ->addCacheableDependency($entity);
    }

    $node = $entity->getTargetNode();
    if ($node === NULL) {
      return AccessResult::forbidden()
        ->addCacheableDependency($entity);
    }

    return $node->access('view', $account, TRUE)
      ->addCacheableDependency($entity);
  }

}
