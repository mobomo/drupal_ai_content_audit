<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Builds the AIRO Preview tab render array.
 */
final class AiroPreviewTabBuilder {

  public function __construct(
    private readonly AiProviderPluginManager $aiProviderManager,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly ProviderModelChoices $providerModelChoices,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Builds the render array for the AI Preview tab.
   */
  public function build(NodeInterface $node, bool $pageSkin = FALSE): array {
    $hasPermission = $this->currentUser->hasPermission('use any ai provider in airo')
      || $this->currentUser->hasPermission('administer ai content audit');

    $allChoices = $this->providerModelChoices->forOperationType('chat');

    if (!$hasPermission || empty($allChoices)) {
      $central = $this->aiProviderManager->getDefaultProviderForOperationType('content_audit')
        ?? $this->aiProviderManager->getDefaultProviderForOperationType('chat');
      $key = $this->providerModelChoices->findKeyForProviderModel(
        (string) ($central['provider_id'] ?? ''),
        (string) ($central['model_id'] ?? ''),
      );
      if ($key !== '') {
        $label = ucwords(str_replace(['-', '_'], ' ', $central['provider_id']));
        $allChoices = [
          [
            'key' => $key,
            'label' => $label,
            'provider_id' => $central['provider_id'],
            'model_id' => $central['model_id'] ?? '',
          ],
        ];
      }
      else {
        $allChoices = [];
      }
    }

    $store = $this->tempStoreFactory->get('ai_content_audit');
    $savedKeys = $store->get('last_provider_models') ?? [];
    $validKeys = array_column($allChoices, 'key');
    $selectedKeys = array_values(
      array_filter($savedKeys, fn($key) => in_array($key, $validKeys, TRUE))
    );
    if (empty($selectedKeys) && !empty($validKeys)) {
      $selectedKeys = [$validKeys[0]];
    }

    return [
      '#theme' => 'ai_preview_tab',
      '#use_page_skin' => $pageSkin,
      '#model_choices' => $allChoices,
      '#selected_keys' => $selectedKeys,
      '#has_permission' => $hasPermission,
      '#suggested_prompts' => [
        'What are the key points of this content?',
        'How would you summarize this page?',
        'What questions does this content leave unanswered?',
      ],
      '#node_id' => $node->id(),
      '#revision_id' => (int) $node->getRevisionId(),
      '#query_url' => Url::fromRoute(
        'ai_content_audit.panel.preview_query',
        ['node' => $node->id()]
      )->toString(),
      '#providers_url' => Url::fromRoute('ai.admin_providers')->toString(),
      '#attached' => [
        'library' => [
          'ai_content_audit/preview-tab',
        ],
      ],
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
