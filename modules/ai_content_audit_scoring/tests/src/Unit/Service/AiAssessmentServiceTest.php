<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit_scoring\Unit\Service;

use Drupal\ai_content_audit\Ai\AiProviderRegistryInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\Plugin\ProviderProxy;
use Drupal\ai_content_audit\Enum\RenderMode;
use Drupal\ai_content_audit\Extractor\ContentExtractorInterface;
use Drupal\ai_content_audit\Extractor\ContentExtractorManager;
use Drupal\ai_content_audit\Service\ProviderModelChoices;
use Drupal\ai_content_audit_scoring\Entity\AiContentAssessment;
use Drupal\ai_content_audit_scoring\Service\AiAssessmentService;
use Drupal\ai_content_audit_scoring\Service\ScoringPromptResolver;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Drupal\Core\Routing\UrlGenerator;

/**
 * Unit tests for AiAssessmentService.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit_scoring\Service\AiAssessmentService
 */
class AiAssessmentServiceTest extends UnitTestCase {

  /**
   * The service under test.
   */
  protected AiAssessmentService $service;

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

    $container = new ContainerBuilder();
    $container->set('cache_tags.invalidator', $this->createMock(CacheTagsInvalidatorInterface::class));
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    $container->set('string_translation', $this->getStringTranslationStub());
    $urlGenerator = $this->createMock(UrlGenerator::class);
    $urlGenerator->method('generateFromRoute')->willReturn('/admin/config/ai/providers');
    $container->set('url_generator', $urlGenerator);
    \Drupal::setContainer($container);

    $aiProvider = $this->createMock(AiProviderRegistryInterface::class);
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
      ['default_provider_model', NULL],
      ['enable_history', TRUE],
    ]);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')->willReturnMap([
      ['ai_content_audit.settings', $config],
      ['ai_content_audit_scoring.settings', $config],
    ]);

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
    $this->assertStringContainsString('No AI chat provider', (string) $result['error']);
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
    $rawJson = '{"ai_readiness_score": 85, "summary": "Good content.", "recommendations": [{"priority": "low", "text": "Add more images."}]}';

    $normalized = $this->createMock(ChatMessage::class);
    $normalized->method('getText')->willReturn($rawJson);

    $mockChatOutput = $this->createMock(ChatOutput::class);
    $mockChatOutput->method('getNormalized')->willReturn($normalized);

    $mockProxy = $this->getMockBuilder(ProviderProxy::class)
      ->disableOriginalConstructor()
      ->addMethods(['chat'])
      ->getMock();
    $mockProxy->method('chat')->willReturn($mockChatOutput);

    // AI provider plugin manager: provider available, defaults resolved.
    $aiProvider = $this->createMock(AiProviderRegistryInterface::class);
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
    $previewConfig = $this->createMock(Config::class);
    $previewConfig->method('get')->willReturnMap([
      ['max_chars_per_request', 8000],
      ['default_provider_model', NULL],
    ]);
    $scoringConfig = $this->createMock(Config::class);
    $scoringConfig->method('get')->willReturnMap([
      ['enable_history', TRUE],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['ai_content_audit.settings', $previewConfig],
      ['ai_content_audit_scoring.settings', $scoringConfig],
    ]);

    // Entity mock: save() must be called exactly once.
    $mockAssessment = $this->createMock(AiContentAssessment::class);
    $mockAssessment->expects($this->once())->method('save');

    // Entity storage mock: create() returns the assessment entity mock.
    $mockStorage = $this->createMock(EntityStorageInterface::class);
    $mockStorage->method('create')->willReturn($mockAssessment);
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $mockStorage->method('getQuery')->willReturn($query);

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
    $this->assertEquals(85, $result['parsed']['ai_readiness_score']);
    $this->assertEquals('Good content.', $result['parsed']['summary']);
  }

}
