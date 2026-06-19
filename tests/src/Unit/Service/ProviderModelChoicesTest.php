<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai_content_audit\Service\ProviderModelChoices;
use PHPUnit\Framework\TestCase;

/**
 * Tests Drupal AI provider/model choice handling.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit\Service\ProviderModelChoices
 */
final class ProviderModelChoicesTest extends TestCase {

  /**
   * Tests choices are sourced from Drupal AI simple options.
   *
   * @covers ::forOperationType
   * @covers ::getSelectOptions
   * @covers ::parseKey
   */
  public function testForOperationTypeUsesSimpleProviderModelOptions(): void {
    $provider = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['getPluginId'])
      ->getMock();
    $provider->method('getPluginId')->willReturn('openai');

    $aiProvider = $this->createMock(AiProviderPluginManager::class);
    $aiProvider->expects($this->once())
      ->method('getSimpleProviderModelOptions')
      ->with('chat', FALSE, TRUE, [AiModelCapability::ChatSystemRole])
      ->willReturn(['openai:gpt-4o' => 'OpenAI - GPT-4o']);
    $aiProvider->method('loadProviderFromSimpleOption')
      ->with('openai:gpt-4o')
      ->willReturn($provider);
    $aiProvider->method('getModelNameFromSimpleOption')
      ->with('openai:gpt-4o')
      ->willReturn('gpt-4o');

    $choices = (new ProviderModelChoices($aiProvider))->forOperationType('chat');

    $this->assertSame([
      [
        'key' => 'openai:gpt-4o',
        'label' => 'GPT-4o',
        'provider_label' => 'openai',
        'provider_id' => 'openai',
        'model_id' => 'gpt-4o',
      ],
    ], $choices);
  }

  /**
   * Tests unavailable providers are skipped gracefully.
   *
   * @covers ::forOperationType
   * @covers ::parseKey
   */
  public function testUnavailableSimpleOptionIsSkipped(): void {
    $aiProvider = $this->createMock(AiProviderPluginManager::class);
    $aiProvider->method('getSimpleProviderModelOptions')
      ->willReturn(['missing:model' => 'Missing model']);
    $aiProvider->method('loadProviderFromSimpleOption')
      ->willThrowException(new \RuntimeException('Provider missing.'));

    $choices = (new ProviderModelChoices($aiProvider))->forOperationType('chat');

    $this->assertSame([], $choices);
  }

  /**
   * Tests grouped Drupal AI options are flattened for AIRO runtime choices.
   *
   * @covers ::forOperationType
   * @covers ::getGroupedSelectOptions
   */
  public function testGroupedOptionsAreFlattenedForRuntimeChoices(): void {
    $provider = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['getPluginId'])
      ->getMock();
    $provider->method('getPluginId')->willReturn('openai');

    $aiProvider = $this->createMock(AiProviderPluginManager::class);
    $aiProvider->method('getSimpleProviderModelOptions')
      ->willReturn([
        'OpenAI' => [
          'openai:gpt-4o' => 'GPT-4o',
        ],
      ]);
    $aiProvider->method('loadProviderFromSimpleOption')
      ->with('openai:gpt-4o')
      ->willReturn($provider);
    $aiProvider->method('getModelNameFromSimpleOption')
      ->with('openai:gpt-4o')
      ->willReturn('gpt-4o');

    $choices = (new ProviderModelChoices($aiProvider))->forOperationType('chat');

    $this->assertSame('openai:gpt-4o', $choices[0]['key']);
    $this->assertSame('GPT-4o', $choices[0]['label']);
  }

  /**
   * Tests legacy provider/model lookup.
   *
   * @covers ::findKeyForProviderModel
   */
  public function testFindKeyForProviderModel(): void {
    $provider = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['getPluginId'])
      ->getMock();
    $provider->method('getPluginId')->willReturn('openai');

    $aiProvider = $this->createMock(AiProviderPluginManager::class);
    $aiProvider->method('getSimpleProviderModelOptions')
      ->willReturn(['simple-key' => 'OpenAI - GPT-4o']);
    $aiProvider->method('loadProviderFromSimpleOption')
      ->with('simple-key')
      ->willReturn($provider);
    $aiProvider->method('getModelNameFromSimpleOption')
      ->with('simple-key')
      ->willReturn('gpt-4o');

    $choices = new ProviderModelChoices($aiProvider);

    $this->assertSame('simple-key', $choices->findKeyForProviderModel('openai', 'gpt-4o'));
    $this->assertSame('', $choices->findKeyForProviderModel('anthropic', 'claude'));
  }

  /**
   * Tests OpenAI catalog entries that leak through chat helpers are hidden.
   *
   * @covers ::forOperationType
   * @covers ::getGroupedSelectOptions
   */
  public function testOpenAiNonConversationalCatalogEntriesAreFiltered(): void {
    $aiProvider = $this->createMock(AiProviderPluginManager::class);
    $aiProvider->method('getSimpleProviderModelOptions')
      ->willReturn([
        'openai__gpt-4o' => 'OpenAI - gpt-4o',
        'openai__gpt-5' => 'OpenAI - gpt-5',
        'openai__gpt-image-1' => 'OpenAI - gpt-image-1',
        'openai__gpt-audio' => 'OpenAI - gpt-audio',
        'openai__gpt-realtime' => 'OpenAI - gpt-realtime',
        'openai__gpt-4o-search-preview' => 'OpenAI - gpt-4o-search-preview',
      ]);
    $aiProvider->method('loadProviderFromSimpleOption')
      ->willReturn(new \stdClass());
    $aiProvider->method('getModelNameFromSimpleOption')
      ->willReturnCallback(static fn (string $key): string => explode('__', $key, 2)[1] ?? '');

    $choices = (new ProviderModelChoices($aiProvider))->forOperationType('chat');

    $this->assertSame(['openai__gpt-4o', 'openai__gpt-5'], array_column($choices, 'key'));
    $this->assertSame(['GPT-4o', 'GPT-5'], array_column($choices, 'label'));
  }

}
