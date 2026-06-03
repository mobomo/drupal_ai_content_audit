<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\EventSubscriber;

use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai_content_audit\EventSubscriber\AiResponseSubscriber;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Unit tests for AiResponseSubscriber.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit\EventSubscriber\AiResponseSubscriber
 */
class AiResponseSubscriberTest extends TestCase {

  /**
   * The subscriber under test.
   */
  protected AiResponseSubscriber $subscriber;

  /**
   * Logger factory mock.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Logger channel mock.
   */
  protected LoggerChannelInterface $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory
      ->method('get')
      ->with('ai_content_audit')
      ->willReturn($this->logger);

    $this->subscriber = new AiResponseSubscriber($this->loggerFactory);
  }

  /**
   * Tests that getSubscribedEvents() returns a non-empty event map.
   *
   * This is a smoke test confirming the subscriber is wired to at least one
   * event, without depending on specific event class constants.
   *
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEventsReturnsNonEmptyArray(): void {
    $events = AiResponseSubscriber::getSubscribedEvents();

    $this->assertIsArray($events);
    $this->assertNotEmpty($events, 'getSubscribedEvents() must return at least one event mapping.');
  }

  /**
   * Tests that the subscriber implements EventSubscriberInterface.
   *
   * This confirms the class is recognised by Symfony's event dispatcher.
   *
   * @covers \Drupal\ai_content_audit\EventSubscriber\AiResponseSubscriber
   */
  public function testSubscriberImplementsEventSubscriberInterface(): void {
    $this->assertInstanceOf(
      EventSubscriberInterface::class,
      $this->subscriber,
      'AiResponseSubscriber must implement EventSubscriberInterface.'
    );
  }

  /**
   * Tests that onPostGenerateResponse logs when the event carries the module tag.
   *
   * When 'ai_content_audit' appears in the event tags the subscriber must call
   * LoggerChannelInterface::debug() exactly once.
   *
   * @covers ::onPostGenerateResponse
   */
  public function testOnPostGenerateResponseLogsWhenTagged(): void {
    // Build a mock PostGenerateResponseEvent that reports the module tag.
    $event = $this->getMockBuilder(PostGenerateResponseEvent::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getTags', 'getProviderId', 'getModelId', 'getRequestThreadId'])
      ->getMock();

    $event->method('getTags')->willReturn(['ai_content_audit', 'some_other_tag']);
    $event->method('getProviderId')->willReturn('openai');
    $event->method('getModelId')->willReturn('gpt-4o');
    $event->method('getRequestThreadId')->willReturn('thread-xyz');

    // The debug logger must be called exactly once.
    $this->logger
      ->expects($this->once())
      ->method('debug')
      ->with($this->stringContains('AI assessment completed'), $this->anything());

    $this->subscriber->onPostGenerateResponse($event);
  }

  /**
   * Tests that onPostGenerateResponse does nothing when the module tag is absent.
   *
   * If 'ai_content_audit' is not in the event tags, the subscriber must return
   * early without calling the logger.
   *
   * @covers ::onPostGenerateResponse
   */
  public function testOnPostGenerateResponseSkipsWhenNotTagged(): void {
    $event = $this->getMockBuilder(PostGenerateResponseEvent::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getTags', 'getProviderId', 'getModelId', 'getRequestThreadId'])
      ->getMock();

    $event->method('getTags')->willReturn(['some_other_module']);
    $event->expects($this->never())->method('getProviderId');

    // Logger must NOT be called.
    $this->logger
      ->expects($this->never())
      ->method('debug');

    $this->subscriber->onPostGenerateResponse($event);
  }

}
