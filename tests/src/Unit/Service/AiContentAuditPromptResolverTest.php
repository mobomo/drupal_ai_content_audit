<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\Service;

use Drupal\ai\Entity\AiPromptInterface;
use Drupal\ai_content_audit\Service\AiContentAuditPromptResolver;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests AI Content Audit prompt resolution.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit\Service\AiContentAuditPromptResolver
 */
final class AiContentAuditPromptResolverTest extends TestCase {

  /**
   * Tests preview prompt variable replacement.
   *
   * @covers ::resolvePreviewPrompts
   */
  public function testResolvePreviewPromptsReplacesVariables(): void {
    $resolver = $this->createResolver([
      AiContentAuditPromptResolver::DEFAULT_PREVIEW_SYSTEM_PROMPT => 'System prompt.',
      AiContentAuditPromptResolver::DEFAULT_PREVIEW_USER_PROMPT => 'Content: {PAGE_CONTENT}. Question: {{ VISITOR_QUESTION }}.',
    ]);

    $prompts = $resolver->resolvePreviewPrompts([
      'PAGE_CONTENT' => 'About Mobomo',
      'VISITOR_QUESTION' => 'What do they do?',
    ]);

    $this->assertSame('System prompt.', $prompts['system_prompt']);
    $this->assertSame('Content: About Mobomo. Question: What do they do?.', $prompts['user_prompt']);
  }

  /**
   * Tests required prompt variables must be present in the prompt text.
   *
   * @covers ::resolvePreviewPrompts
   */
  public function testResolvePreviewPromptsRequiresVariablesInPromptText(): void {
    $resolver = $this->createResolver([
      AiContentAuditPromptResolver::DEFAULT_PREVIEW_SYSTEM_PROMPT => 'System prompt.',
      AiContentAuditPromptResolver::DEFAULT_PREVIEW_USER_PROMPT => 'Answer naturally.',
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('must include the PAGE_CONTENT variable');

    $resolver->resolvePreviewPrompts([
      'PAGE_CONTENT' => 'About Mobomo',
      'VISITOR_QUESTION' => 'What do they do?',
    ]);
  }

  /**
   * Builds a resolver backed by prompt text keyed by prompt entity ID.
   *
   * @param array<string, string> $prompts
   *   Prompt text keyed by prompt ID.
   */
  private function createResolver(array $prompts): AiContentAuditPromptResolver {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturn(NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ai_content_audit.settings')
      ->willReturn($settings);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')
      ->willReturnCallback(function (string $id) use ($prompts): ?AiPromptInterface {
        if (!isset($prompts[$id])) {
          return NULL;
        }
        $prompt = $this->createMock(AiPromptInterface::class);
        $prompt->method('getPrompt')->willReturn($prompts[$id]);
        return $prompt;
      });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('ai_prompt')
      ->willReturn($storage);

    return new AiContentAuditPromptResolver($configFactory, $entityTypeManager);
  }

}
