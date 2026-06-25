<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit_scoring\Unit\Plugin\QueueWorker;

use Drupal\ai_content_audit_scoring\Plugin\QueueWorker\AiAssessmentQueueWorker;
use Drupal\ai_content_audit_scoring\Service\AiAssessmentService;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\RequeueException;
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
   */
  protected AiAssessmentService $assessmentService;

  /**
   * Entity type manager mock.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Node storage mock.
   */
  protected EntityStorageInterface $nodeStorage;

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

    $this->assessmentService = $this->createMock(AiAssessmentService::class);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('node')
      ->willReturn($this->nodeStorage);

    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory
      ->method('get')
      ->with('ai_content_audit')
      ->willReturn($this->logger);

    $this->worker = new AiAssessmentQueueWorker(
      [],
      'ai_content_audit_assessment',
      [],
      $this->assessmentService,
      $this->entityTypeManager,
      $this->loggerFactory,
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

    // Assertion: we reached this point without an exception.
    $this->assertTrue(TRUE);
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

    $this->assertTrue(TRUE);
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

    $this->assertTrue(TRUE);
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
