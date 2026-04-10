<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_site_audit\Unit\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_site_audit\Service\SiteAnalysisService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the SiteAnalysisService.
 *
 * @coversDefaultClass \Drupal\ai_site_audit\Service\SiteAnalysisService
 * @group ai_site_audit
 */
class SiteAnalysisServiceTest extends TestCase {

  protected AiProviderPluginManager $aiProvider;
  protected KeyValueExpirableFactoryInterface $kvFactory;
  protected StateInterface $state;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->aiProvider = $this->createMock(AiProviderPluginManager::class);
    $this->kvFactory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $this->state = $this->createMock(StateInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    // Default config.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['analysis_cooldown_hours', 24],
        ['provider_id', NULL],
        ['model_id', NULL],
      ]);
    $this->configFactory->method('get')
      ->willReturn($config);
  }

  protected function createService(): SiteAnalysisService {
    return new SiteAnalysisService(
      $this->aiProvider,
      $this->kvFactory,
      $this->state,
      $this->configFactory,
      $this->logger,
    );
  }

  /**
   * @covers ::parseJsonResponse
   */
  public function testParseJsonResponseDirect(): void {
    $service = $this->createService();
    $json = '{"overall_grade":"B","executive_summary":"Good site"}';
    $result = $service->parseJsonResponse($json);

    $this->assertIsArray($result);
    $this->assertEquals('B', $result['overall_grade']);
    $this->assertEquals('Good site', $result['executive_summary']);
  }

  /**
   * @covers ::parseJsonResponse
   */
  public function testParseJsonResponseWithMarkdownFence(): void {
    $service = $this->createService();
    $raw = "Here is the analysis:\n```json\n{\"overall_grade\":\"A\"}\n```\nEnd.";
    $result = $service->parseJsonResponse($raw);

    $this->assertIsArray($result);
    $this->assertEquals('A', $result['overall_grade']);
  }

  /**
   * @covers ::parseJsonResponse
   */
  public function testParseJsonResponseWithEmbeddedJson(): void {
    $service = $this->createService();
    $raw = "The result is: {\"overall_grade\":\"C\",\"quick_wins\":[]} and more text.";
    $result = $service->parseJsonResponse($raw);

    $this->assertIsArray($result);
    $this->assertEquals('C', $result['overall_grade']);
  }

  /**
   * @covers ::parseJsonResponse
   */
  public function testParseJsonResponseInvalidReturnsNull(): void {
    $service = $this->createService();
    $result = $service->parseJsonResponse('This is not JSON at all.');
    $this->assertNull($result);
  }

  /**
   * @covers ::canRunAnalysis
   */
  public function testCanRunAnalysisWithinCooldown(): void {
    // Last analysis was 1 hour ago, cooldown is 24h.
    $this->state->method('get')
      ->willReturnMap([
        ['ai_site_audit.last_ai_analysis_time', 0, time() - 3600],
      ]);

    $this->aiProvider->method('getSimpleProviderModelOptions')
      ->willReturn(['openai' => ['gpt-4' => 'GPT-4']]);

    $service = $this->createService();
    $result = $service->canRunAnalysis();

    $this->assertFalse($result['allowed']);
    $this->assertStringContainsString('Cooldown', $result['reason']);
  }

  /**
   * @covers ::canRunAnalysis
   */
  public function testCanRunAnalysisAfterCooldown(): void {
    // Last analysis was 25 hours ago, cooldown is 24h.
    $this->state->method('get')
      ->willReturnMap([
        ['ai_site_audit.last_ai_analysis_time', 0, time() - 90000],
      ]);

    $this->aiProvider->method('getSimpleProviderModelOptions')
      ->willReturn(['openai' => ['gpt-4' => 'GPT-4']]);

    $service = $this->createService();
    $result = $service->canRunAnalysis();

    $this->assertTrue($result['allowed']);
  }

  /**
   * @covers ::canRunAnalysis
   */
  public function testCanRunAnalysisNoProviders(): void {
    $this->state->method('get')
      ->willReturnMap([
        ['ai_site_audit.last_ai_analysis_time', 0, 0],
      ]);

    $this->aiProvider->method('getSimpleProviderModelOptions')
      ->willReturn([]);

    $service = $this->createService();
    $result = $service->canRunAnalysis();

    $this->assertFalse($result['allowed']);
    $this->assertStringContainsString('No AI chat providers', $result['reason']);
  }

  /**
   * @covers ::getCachedInterpretation
   */
  public function testGetCachedInterpretationReturnsData(): void {
    $kvStore = $this->createMock(KeyValueStoreExpirableInterface::class);
    $kvStore->method('get')
      ->with('ai_interpretation')
      ->willReturn(['overall_grade' => 'B', 'analyzed_at' => time()]);

    $this->kvFactory->method('get')
      ->with('ai_site_audit')
      ->willReturn($kvStore);

    $service = $this->createService();
    $result = $service->getCachedInterpretation();

    $this->assertIsArray($result);
    $this->assertEquals('B', $result['overall_grade']);
  }

  /**
   * @covers ::getCachedInterpretation
   */
  public function testGetCachedInterpretationReturnsNullWhenEmpty(): void {
    $kvStore = $this->createMock(KeyValueStoreExpirableInterface::class);
    $kvStore->method('get')
      ->with('ai_interpretation')
      ->willReturn(NULL);

    $this->kvFactory->method('get')
      ->with('ai_site_audit')
      ->willReturn($kvStore);

    $service = $this->createService();
    $result = $service->getCachedInterpretation();

    $this->assertNull($result);
  }

}
