<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit_scoring\Unit\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_content_audit\Enum\RenderMode;
use Drupal\ai_content_audit\Extractor\ContentExtractorInterface;
use Drupal\ai_content_audit\Extractor\ContentExtractorManager;
use Drupal\ai_content_audit\Service\ProviderModelChoices;
use Drupal\ai_content_audit_scoring\Service\AiAssessmentService;
use Drupal\ai_content_audit_scoring\Service\ScoringPromptResolver;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AiAssessmentService.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit_scoring\Service\AiAssessmentService
 */
class AiAssessmentServiceTest extends TestCase {

  /**
   * The service under test.
   */
  protected AiAssessmentService $service;

  /**
   * AI provider plugin manager mock.
   */
  protected MockObject $aiProvider;

  /**
   * Content extractor manager mock.
   */
  protected ContentExtractorManager $extractorManager;

  /**
   * Content extractor mock.
   *
   * @var \Drupal\ai_content_audit\Extractor\ContentExtractorInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected ContentExtractorInterface $mockExtractor;

  /**
   * Logger factory mock.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Config factory mock.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Prompt resolver mock.
   */
  protected ScoringPromptResolver $promptResolver;

  /**
   * Provider/model choices helper mock.
   */
  protected ProviderModelChoices $providerModelChoices;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $aiProvider = $this->createMock(AiProviderPluginManager::class);
    $this->aiProvider = $aiProvider;

    // Create a mock extractor that the manager will return.
    $this->mockExtractor = $this->createMock(ContentExtractorInterface::class);

    // Create the manager mock and configure it to return the extractor mock.
    $this->extractorManager = $this->createMock(ContentExtractorManager::class);
    $this->extractorManager->method('getExtractorForMode')
      ->willReturn($this->mockExtractor);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->method('get')->willReturn($logger);

    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['max_chars_per_request', 8000],
    ]);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')->willReturn($config);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->promptResolver = $this->createMock(ScoringPromptResolver::class);
    $this->promptResolver->method('resolveAssessmentPrompts')->willReturn([
      'system_prompt' => 'Assessment system prompt.',
      'user_prompt' => 'Assessment user prompt.',
    ]);
    $this->providerModelChoices = $this->createMock(ProviderModelChoices::class);

    $this->service = new AiAssessmentService(
      $aiProvider,
      $this->extractorManager,
      $this->loggerFactory,
      $this->configFactory,
      $entityTypeManager,
      $this->promptResolver,
      $this->providerModelChoices,
    );
  }

  /**
   * Tests that assessNode returns failure when no provider is available.
   *
   * @covers ::assessNode
   */
  public function testAssessNodeFailsWhenNoProviderAvailable(): void {
    $this->aiProvider->method('hasProvidersForOperationType')->willReturn(FALSE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(1);

    $result = $this->service->assessNode($node);

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('No AI chat provider', $result['error']);
  }

  /**
   * Tests that assessNode returns failure when no content is extractable.
   *
   * @covers ::assessNode
   */
  public function testAssessNodeFailsWhenNoContentExtracted(): void {
    $this->aiProvider->method('hasProvidersForOperationType')->willReturn(TRUE);
    $this->aiProvider->method('getDefaultProviderForOperationType')->willReturn([
      'provider_id' => 'openai',
      'model_id' => 'gpt-4o',
    ]);
    $this->mockExtractor->method('extract')->willReturn('   ');

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(1);
    $node->method('bundle')->willReturn('article');

    $result = $this->service->assessNode($node);

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('No content to assess', $result['error']);
  }

  /**
   * Tests parseJsonResponse with valid JSON input.
   *
   * @covers ::parseJsonResponse
   */
  public function testParseJsonResponseWithValidJson(): void {
    $json = '{"ai_readiness_score": 75, "readability": {"grade_level": 8, "assessment": "Good"}}';
    $result = $this->service->parseJsonResponse($json);

    $this->assertIsArray($result);
    $this->assertEquals(75, $result['ai_readiness_score']);
  }

  /**
   * Tests parseJsonResponse strips markdown code fences.
   *
   * @covers ::parseJsonResponse
   */
  public function testParseJsonResponseStripsCodeFences(): void {
    $json = "```json\n{\"ai_readiness_score\": 60}\n```";
    $result = $this->service->parseJsonResponse($json);

    $this->assertIsArray($result);
    $this->assertEquals(60, $result['ai_readiness_score']);
  }

  /**
   * Tests parseJsonResponse extracts JSON from surrounding prose.
   *
   * @covers ::parseJsonResponse
   */
  public function testParseJsonResponseExtractsFromProse(): void {
    $raw = 'Here is your assessment:\n{"ai_readiness_score": 50}\nThank you.';
    $result = $this->service->parseJsonResponse($raw);

    $this->assertIsArray($result);
    $this->assertEquals(50, $result['ai_readiness_score']);
  }

  /**
   * Tests parseJsonResponse returns NULL for completely invalid input.
   *
   * @covers ::parseJsonResponse
   */
  public function testParseJsonResponseReturnsNullForInvalidInput(): void {
    $result = $this->service->parseJsonResponse('this is not json at all');
    $this->assertNull($result);
  }

  /**
   * Tests that assessNode returns success on the happy path.
   *
   * Covers the full flow: provider available → content extracted →
   * AI returns valid JSON → entity created and saved → success array returned.
   *
   * @covers ::assessNode
   */
  public function testAssessReturnsSuccessOnHappyPath(): void {
    $rawJson = '{"score": 85, "summary": "Good content.", "recommendations": [{"priority": "low", "text": "Add more images."}]}';

    // Mock the getText() carrier returned by getNormalized().
    $mockNormalized = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['getText'])
      ->getMock();
    $mockNormalized->method('getText')->willReturn($rawJson);

    // Mock the chat() output object whose getNormalized() returns the above.
    $mockChatOutput = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['getNormalized'])
      ->getMock();
    $mockChatOutput->method('getNormalized')->willReturn($mockNormalized);

    // Mock the AI provider proxy returned by createInstance().
    $mockProxy = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['chat'])
      ->getMock();
    $mockProxy->method('chat')->willReturn($mockChatOutput);

    // AI provider plugin manager: provider available, defaults resolved.
    $aiProvider = $this->createMock(AiProviderPluginManager::class);
    $aiProvider->method('hasProvidersForOperationType')->willReturn(TRUE);
    $aiProvider->method('getDefaultProviderForOperationType')->willReturnMap([
      ['content_audit', NULL],
      ['chat', ['provider_id' => 'openai', 'model_id' => 'gpt-4o']],
    ]);
    $aiProvider->method('createInstance')->willReturn($mockProxy);

    // Content extractor mock returns real, non-empty content.
    $mockExtractor = $this->createMock(ContentExtractorInterface::class);
    $mockExtractor->method('extract')->willReturn('Some node content for testing.');

    // Extractor manager mock routes to the content extractor mock.
    $extractorManager = $this->createMock(ContentExtractorManager::class);
    $extractorManager->method('getExtractorForMode')
      ->with(RenderMode::Text->value)
      ->willReturn($mockExtractor);

    // Logger (silent in happy path).
    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    // Config: enable_history = TRUE so the pruning branch is skipped entirely.
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['max_chars_per_request', 8000],
      ['enable_history', TRUE],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    // Entity mock: save() must be called exactly once.
    $mockAssessment = $this->createMock(EntityInterface::class);
    $mockAssessment->expects($this->once())->method('save');

    // Entity storage mock: create() returns the assessment entity mock.
    $mockStorage = $this->createMock(EntityStorageInterface::class);
    $mockStorage->method('create')->willReturn($mockAssessment);

    // Entity type manager mock: getStorage() returns the storage mock.
    $mockEntityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $mockEntityTypeManager->method('getStorage')->willReturn($mockStorage);
    $promptResolver = $this->createMock(ScoringPromptResolver::class);
    $promptResolver->expects($this->once())
      ->method('resolveAssessmentPrompts')
      ->with($this->callback(static fn(array $variables): bool => isset(
        $variables['DETERMINISTIC_SIGNALS'],
        $variables['SEO_SIGNALS'],
        $variables['CONTENT'],
        $variables['RESPONSE_SCHEMA']
      )))
      ->willReturn([
        'system_prompt' => 'Assessment system prompt.',
        'user_prompt' => 'Assessment user prompt.',
      ]);
    $providerModelChoices = $this->createMock(ProviderModelChoices::class);

    // Node under assessment.
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(42);
    $node->method('bundle')->willReturn('article');

    // Instantiate the service with all five constructor arguments.
    $service = new AiAssessmentService(
      $aiProvider,
      $extractorManager,
      $loggerFactory,
      $configFactory,
      $mockEntityTypeManager,
      $promptResolver,
      $providerModelChoices,
    );

    $result = $service->assessNode($node);

    $this->assertTrue($result['success']);
    $this->assertEquals(85, $result['parsed']['score']);
    $this->assertEquals('Good content.', $result['parsed']['summary']);
  }

}
