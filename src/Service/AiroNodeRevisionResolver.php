<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Resolves the node revision targeted by AIRO AJAX requests.
 */
final class AiroNodeRevisionResolver {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Uses optional JSON `revision_id` to load the node revision being edited.
   *
   * The route `{node}` is often the default revision; node forms and AIRO
   * clients send the form entity revision ID so drafts and Layout Builder
   * overrides match what the editor sees.
   *
   * @param \Drupal\node\NodeInterface $routeNode
   *   Node provided by the route parameter.
   * @param array<string, mixed> $decoded
   *   Decoded JSON request body.
   *
   * @return \Drupal\node\NodeInterface
   *   The revision to assess, or the route node when no revision is specified.
   */
  public function resolveFromRequestBody(NodeInterface $routeNode, array $decoded): NodeInterface {
    if (!$routeNode->access('view', $this->currentUser)) {
      throw new AccessDeniedHttpException();
    }

    if (empty($decoded['revision_id'])) {
      return $routeNode;
    }

    $vid = (int) $decoded['revision_id'];
    if ($vid <= 0) {
      throw new AccessDeniedHttpException();
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $revision = $storage->loadRevision($vid);
    if (!$revision instanceof NodeInterface) {
      throw new AccessDeniedHttpException();
    }

    if ((int) $revision->id() !== (int) $routeNode->id()) {
      throw new AccessDeniedHttpException();
    }

    if (!$revision->access('view', $this->currentUser)) {
      throw new AccessDeniedHttpException();
    }

    if (!$revision->access('view revision', $this->currentUser)) {
      throw new AccessDeniedHttpException();
    }

    return $revision;
  }

}
