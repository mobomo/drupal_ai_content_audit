<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit_scoring\Unit\Service;

use Drupal\ai\Entity\AiPromptInterface;
use Drupal\ai_content_audit_scoring\Service\ScoringPromptResolver;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests AIRO Scoring prompt resolution.
 *
 * @group ai_content_audit_scoring
 * @coversDefaultClass \Drupal\ai_content_audit_scoring\Service\ScoringPromptResolver
 */
final class ScoringPromptResolverTest extends TestCase {

  /**
   * Tests actionable errors for missing prompts.
   *
   * @covers ::resolveAssessmentPrompts
   */
  public function testResolveAssessmentPromptsFailsWhenPromptMissing(): void {
    $resolver = $this->createResolver([]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('references missing AI Prompt entity');

    $resolver->resolveAssessmentPrompts([
      'DETERMINISTIC_SIGNALS' => '- h1_markers_count: 1',
      'SEO_SIGNALS' => '- meta_description_present: true',
      'CONTENT' => 'Page content',
      'RESPONSE_SCHEMA' => '{}',
    ]);
  }

  /**
   * Builds a resolver backed by prompt text keyed by prompt entity ID.
   *
   * @param array<string, string> $prompts
   *   Prompt text keyed by prompt ID.
   */
  private function createResolver(array $prompts): ScoringPromptResolver {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturn(NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ai_content_audit_scoring.settings')
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

    return new ScoringPromptResolver($configFactory, $entityTypeManager);
  }

}
