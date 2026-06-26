<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Plugin\QueueWorker;

use Drupal\ai_content_audit_scoring\Service\AiAssessmentService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes AI content assessment jobs from the queue.
 *
 * Queue item structure: ['nid' => int, 'options' => array (optional)]
 *
 * Run with: drush queue:run ai_content_audit_assessment
 */
#[QueueWorker(
  id: 'ai_content_audit_assessment',
  title: new TranslatableMarkup('AI Content Audit Assessment'),
  cron: ['time' => 60],
)]
final class AiAssessmentQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly AiAssessmentService $assessmentService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai_content_audit_scoring.assessment_service'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array $data
   *   Queue item data with keys:
   *   - 'nid' (int): The node ID to assess.
   *   - 'options' (array): Optional overrides passed directly to
   *     AiAssessmentService::assessNode(). Valid keys:
   *     - 'provider_id' (string): AI provider machine name.
   *     - 'model_id' (string): AI model machine name.
   *     - 'render_mode' (string): Content extraction mode. One of the
   *       \Drupal\ai_content_audit\Enum\RenderMode enum values:
   *       'text' (default), 'html', 'screenshot'.
   *       Note: 'screenshot' mode requires Node.js + Puppeteer on the server
   *       and a vision-capable AI model. Do not enqueue screenshot-mode items
   *       from synchronous request paths.
   *
   * @throws \Drupal\Core\Queue\RequeueException
   *   When assessment fails transiently (provider unavailable, rate limit).
   * @throws \Drupal\Core\Queue\SuspendQueueException
   *   When the queue should halt (quota exhausted, catastrophic failure).
   */
  public function processItem(mixed $data): void {
    $logger = $this->loggerFactory->get('ai_content_audit');

    $data = (array) $data;
    $nid = $data['nid'] ?? NULL;
    $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];

    if (!$nid) {
      // Malformed item — log and discard permanently.
      $logger->error('Queue item missing nid. Data: @data', [
        '@data' => print_r($data, TRUE),
      ]);
      return;
    }

    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    // Bulk queue uses default revision (published nodes). For drafts, use
    // loadRevision() when enqueueing per-revision assessment.
    if (!$node instanceof NodeInterface) {
      // Node was deleted — discard permanently.
      $logger->warning('Queue item for nid @nid skipped: node not found.', [
        '@nid' => $nid,
      ]);
      return;
    }

    // Idempotent deduplication: if an assessment for this node was created
    // within the last 5 minutes, the queue item is a duplicate (e.g. from
    // rapid successive saves).  Consume the item without re-assessing.
    $storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    $recentCount = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_node', $nid)
      ->condition('created', $this->time->getRequestTime() - 300, '>')
      ->count()
      ->execute();

    if ($recentCount > 0) {
      $logger->debug(
        'Skipping node @nid — assessed within the last 5 minutes.',
        ['@nid' => $nid],
      );
      return;
    }

    try {
      $result = $this->assessmentService->assessNode($node, $options);

      if (!$result['success']) {
        $error      = $result['error'] ?? 'unknown';
        $errorLower = strtolower($error);

        // M-4: Only requeue for transient failures (rate limits, server errors,
        // timeouts, network issues).  Permanent failures are logged and
        // discarded so they do not loop forever burning API credits.
        if (
          str_contains($errorLower, '429') ||
          str_contains($errorLower, '503') ||
          str_contains($errorLower, 'timeout') ||
          str_contains($errorLower, 'network')
        ) {
          throw new RequeueException(
            sprintf('Transient AI assessment failure for nid %d: %s', $nid, $error)
          );
        }

        // Permanent failure — log and discard.
        $logger->error('Permanent AI assessment failure for nid @nid: @error', [
          '@nid'   => $nid,
          '@error' => $error,
        ]);
        return;
      }
    }
    catch (RequeueException $e) {
      // Re-queue: release the item back so it can be retried.
      $logger->warning('Requeueing nid @nid: @msg', [
        '@nid' => $nid,
        '@msg'  => $e->getMessage(),
      ]);
      throw $e;
    }
    catch (SuspendQueueException $e) {
      // Halt the entire queue worker for this cron run.
      $logger->critical('Queue suspended for ai_content_audit_assessment: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      throw $e;
    }
    catch (\InvalidArgumentException | \TypeError $e) {
      // M-4: Permanent failures from malformed data — log and discard.
      $logger->error(
        'Permanent failure (malformed data) processing AI assessment for nid @nid: @msg',
        ['@nid' => $nid, '@msg' => $e->getMessage()]
      );
    }
    catch (\Exception $e) {
      // Permanent failure — log and discard (normal return deletes item).
      $logger->error(
        'Permanent failure processing AI assessment for nid @nid: @msg',
        ['@nid' => $nid, '@msg' => $e->getMessage()]
      );
    }
  }

}
