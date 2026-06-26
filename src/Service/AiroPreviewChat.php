<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_content_audit\Enum\RenderMode;
use Drupal\ai_content_audit\Extractor\ContentExtractorManager;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Handles AIRO Preview chat prompt assembly and provider calls.
 */
final class AiroPreviewChat {

  public function __construct(
    protected AiProviderPluginManager $aiProviderManager,
    protected ContentExtractorManager $contentExtractorManager,
    protected ProviderModelChoices $providerModelChoices,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected AiContentAuditPromptResolver $promptResolver,
    protected AccountInterface $currentUser,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Executes a preview query and returns the JSON response.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node revision to use as preview context.
   * @param array<string, mixed> $body
   *   Decoded JSON request body.
   */
  public function submit(NodeInterface $node, array $body): JsonResponse {
    $question = trim((string) ($body['question'] ?? ''));
    if ($question === '') {
      return new JsonResponse(['error' => 'Please enter a question.'], 400);
    }

    $requestedKeys = array_filter((array) ($body['provider_models'] ?? []));
    $hasPermission = $this->currentUser->hasPermission('use any ai provider in airo')
      || $this->currentUser->hasPermission('administer ai content audit');

    if (!$hasPermission || empty($requestedKeys)) {
      $central = $this->aiProviderManager->getDefaultProviderForOperationType('content_audit')
        ?? $this->aiProviderManager->getDefaultProviderForOperationType('chat');
      $defaultKey = $this->providerModelChoices->findKeyForProviderModel(
        (string) ($central['provider_id'] ?? ''),
        (string) ($central['model_id'] ?? ''),
      );
      if ($defaultKey !== '') {
        $requestedKeys = [$defaultKey];
      }
      else {
        return new JsonResponse([
          'error' => 'No AI provider is configured.',
          'error_hint' => 'api_key',
        ], 500);
      }
    }

    $nodeContent = $this->extractRenderedNodeContext($node);
    try {
      ['system_prompt' => $systemPrompt, 'user_prompt' => $userPrompt] = $this->promptResolver->resolvePreviewPrompts([
        'PAGE_CONTENT' => $nodeContent,
        'VISITOR_QUESTION' => $question,
      ]);
    }
    catch (\RuntimeException $e) {
      $this->logger->error('Preview prompt resolution failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'The AI preview prompts are not configured correctly.',
        'detail' => $e->getMessage(),
      ], 500);
    }

    $labelMap = array_column(
      $this->providerModelChoices->forOperationType('chat'),
      'label',
      'key'
    );

    $results = [];
    $successKeys = [];
    foreach ($requestedKeys as $key) {
      [$provider, $modelId] = $this->providerModelChoices->loadFromKey((string) $key);
      [$providerId] = $this->providerModelChoices->parseKey((string) $key);
      if ($provider === NULL || $providerId === '' || $modelId === '') {
        continue;
      }

      $label = $labelMap[$key] ?? ucwords(str_replace(['-', '_'], ' ', $providerId));
      $oneResult = $this->queryOneProvider($systemPrompt, $userPrompt, $provider, $providerId, $modelId);

      $results[] = [
        'key' => $key,
        'provider_id' => $providerId,
        'model_id' => $modelId,
        'label' => $label,
        'html' => $oneResult['html'],
        'duration_ms' => $oneResult['duration_ms'],
        'error' => $oneResult['error'],
        'error_hint' => $oneResult['error_hint'] ?? NULL,
      ];

      if ($oneResult['error'] === NULL) {
        $successKeys[] = $key;
      }
    }

    if ($successKeys !== []) {
      $store = $this->tempStoreFactory->get('ai_content_audit');
      $store->set('last_provider_models', $successKeys);
    }

    $cacheability = (new CacheableMetadata())->setCacheMaxAge(0);
    $response = new CacheableJsonResponse([
      'results' => $results,
      'question' => $question,
    ]);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Calls one AI provider+model and returns a normalized result array.
   *
   * @return array{html: string|null, duration_ms: int, error: string|null, error_hint: string|null}
   *   Normalized provider response payload.
   */
  private function queryOneProvider(
    string $systemPrompt,
    string $userPrompt,
    object $provider,
    string $providerId,
    string $modelId,
  ): array {
    $start = microtime(TRUE);

    try {
      if (!$this->aiProviderManager->hasProvidersForOperationType('chat')) {
        throw new \RuntimeException('No AI chat provider is configured.');
      }

      $chatInput = new ChatInput([
        new ChatMessage('system', $systemPrompt),
        new ChatMessage('user', $userPrompt),
      ]);

      $output = $provider->chat($chatInput, $modelId, ['ai_content_audit', 'preview']);
      $text = $output->getNormalized()->getText();

      return [
        'html' => $this->simpleMarkdownToHtml($text),
        'duration_ms' => (int) round((microtime(TRUE) - $start) * 1000),
        'error' => NULL,
        'error_hint' => NULL,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Preview query failed for @provider/@model: @msg', [
        '@provider' => $providerId,
        '@model' => $modelId,
        '@msg' => $e->getMessage(),
      ]);

      return [
        'html' => NULL,
        'duration_ms' => (int) round((microtime(TRUE) - $start) * 1000),
        'error' => $this->formatPreviewUserErrorMessage($e),
        'error_hint' => $this->detectPreviewErrorHint($e->getMessage()),
      ];
    }
  }

  /**
   * Returns a short, user-safe preview error.
   */
  private function formatPreviewUserErrorMessage(\Exception $e): string {
    $lower = strtolower($e->getMessage());
    if (str_contains($lower, 'no ai chat provider')) {
      return 'No AI provider is configured for this site.';
    }
    return 'The AI model could not generate a response. Please try again or check your provider configuration.';
  }

  /**
   * Maps provider error messages to UI hints shown in the preview chat.
   */
  private function detectPreviewErrorHint(string $message): ?string {
    $lower = strtolower($message);
    if (preg_match('/api.?key|authentication|unauthorized|invalid.?key|credential|bearer|not configured|no ai chat provider/', $lower)) {
      return 'api_key';
    }
    return NULL;
  }

  /**
   * Builds AI Preview page context via the HTML extractor.
   */
  private function extractRenderedNodeContext(NodeInterface $node): string {
    try {
      return $this->contentExtractorManager
        ->getExtractorForMode(RenderMode::Html->value)
        ->extract($node);
    }
    catch (\Throwable $e) {
      $this->logger->warning('AI Preview: HTML extraction failed; using title/body fallback. @message', [
        '@message' => $e->getMessage(),
      ]);
      return $this->extractNodeContentFallback($node);
    }
  }

  /**
   * Minimal fallback when HTML extraction is not available.
   */
  private function extractNodeContentFallback(NodeInterface $node): string {
    $content = 'Title: ' . $node->getTitle() . "\n\n";
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      /** @var \Drupal\text\Plugin\Field\FieldType\TextWithSummaryItem $body */
      $body = $node->get('body')->first();
      $content .= strip_tags($body->value ?? '') . "\n";
    }
    return $content;
  }

  /**
   * Converts a basic subset of Markdown to HTML for response display.
   */
  private function simpleMarkdownToHtml(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $text = (string) preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
    $text = (string) preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
    $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = (string) preg_replace('/^- (.+)$/m', '<li>$1</li>', $text);
    $text = (string) preg_replace('/(<li>.*?<\/li>\n?)+/s', '<ul>$0</ul>', $text);
    $text = (string) preg_replace('/\n{2,}/', '</p><p>', $text);
    $text = '<p>' . $text . '</p>';
    $text = (string) preg_replace('/<p>\s*<\/p>/', '', $text);
    $text = (string) preg_replace('/<p>(<(?:ul|ol|h[2-6])[^>]*>)/i', '$1', $text);
    return (string) preg_replace('/(<\/(?:ul|ol|h[2-6])>)<\/p>/i', '$1', $text);
  }

}
