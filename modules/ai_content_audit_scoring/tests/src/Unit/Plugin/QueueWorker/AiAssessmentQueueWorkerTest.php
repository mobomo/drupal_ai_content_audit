<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit_scoring\Unit\Plugin\QueueWorker;

use Drupal\ai_content_audit_scoring\Plugin\QueueWorker\AiAssessmentQueueWorker;
use Drupal\ai_content_audit_scoring\Service\AiAssessmentService;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AiAssessmentQueueWorker.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit_scoring\Plugin\QueueWorker\AiAssessmentQueueWorker
 */
class AiAssessmentQueueWorkerTest extends TestCase {

  /**
   * The queue worker under test.
   */
  protected AiAssessmentQueueWorker $worker;

  /**
   * Assessment service mock.
   *
   * @var \Drupal\ai_content_audit_scoring\Service\AiAssessmentService&\PHPUnit\Framework\MockObject\MockObject
   */
  protected AiAssessmentService $assessmentService;

  /**
   * Entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Node storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $nodeStorage;

  /**
   * Logger factory mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerChannelInterface $logger;

  /**
   * Assessment entity storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $assessmentStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->assessmentService = $this->createMock(AiAssessmentService::class);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->assessmentStorage = $this->createMock(EntityStorageInterface::class);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('count')->willReturnSelf();
    $query->method('execute')->willReturn(0);
    $this->assessmentStorage->method('getQuery')->willReturn($query);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->willReturnMap([
        ['node', $this->nodeStorage],
        ['ai_content_assessment', $this->assessmentStorage],
      ]);

    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory
      ->method('get')
      ->with('ai_content_audit')
      ->willReturn($this->logger);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_000);

    $this->worker = new AiAssessmentQueueWorker(
      [],
      'ai_content_audit_assessment',
      [],
      $this->assessmentService,
      $this->entityTypeManager,
      $this->loggerFactory,
      $time,
    );
  }

  /**
   * Tests that processItem completes without exception on success.
   *
   * @covers ::processItem
   */
  public function testProcessItemSucceeds(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(42);

    $this->nodeStorage
      ->method('load')
      ->with(42)
      ->willReturn($node);

    $this->assessmentService
      ->method('assessNode')
      ->willReturn(['success' => TRUE, 'parsed' => ['score' => 80]]);

    // No exception expected — processItem should return cleanly.
    $this->worker->processItem(['nid' => 42]);
  }

  /**
   * Tests that a queue item for a deleted node is discarded without requeueing.
   *
   * When Node::load() returns NULL the worker should log and return, not throw
   * RequeueException.
   *
   * @covers ::processItem
   */
  public function testProcessItemDiscardsMissingNode(): void {
    $this->nodeStorage
      ->method('load')
      ->with(99)
      ->willReturn(NULL);

    // Logger should emit a warning about the missing node.
    $this->logger
      ->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('node not found'), $this->anything());

    // assessNode must NOT be called for a missing node.
    $this->assessmentService
      ->expects($this->never())
      ->method('assessNode');

    // No RequeueException should propagate.
    $this->worker->processItem(['nid' => 99]);
  }

  /**
   * Tests that a permanent failure result is logged and discarded (no requeue).
   *
   * An error string that does not match transient patterns (429, 503, timeout,
   * network) must NOT trigger a RequeueException.
   *
   * @covers ::processItem
   */
  public function testProcessItemDiscardsOnPermanentFailure(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(7);

    $this->nodeStorage
      ->method('load')
      ->willReturn($node);

    $this->assessmentService
      ->method('assessNode')
      ->willReturn(['success' => FALSE, 'error' => 'JSON parse failed']);

    // The error should be logged as a permanent failure.
    $this->logger
      ->expects($this->once())
      ->method('error')
      ->with($this->stringContains('Permanent AI assessment failure'), $this->anything());

    // Should complete without throwing any exception.
    $this->worker->processItem(['nid' => 7]);
  }

  /**
   * Tests that a transient failure (rate-limit) causes RequeueException.
   *
   * Error strings containing '429' must be re-queued so they can be retried
   * once the rate-limit window expires.
   *
   * @covers ::processItem
   */
  public function testProcessItemRequeuesOnTransientFailure(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(3);

    $this->nodeStorage
      ->method('load')
      ->willReturn($node);

    $this->assessmentService
      ->method('assessNode')
      ->willReturn(['success' => FALSE, 'error' => '429 rate limit exceeded']);

    $this->expectException(RequeueException::class);

    $this->worker->processItem(['nid' => 3]);
  }

}
