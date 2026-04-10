<?php

declare(strict_types=1);

namespace Drupal\ai_site_audit\Plugin\QueueWorker;

use Drupal\ai_site_audit\Service\AnalysisOrchestrator;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes sitewide AI audit analysis asynchronously.
 */
#[QueueWorker(
  id: 'ai_site_audit_analysis',
  title: new TranslatableMarkup('Sitewide AI Audit Analysis'),
  cron: ['time' => 30],
)]
class SitewideAnalysisQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AnalysisOrchestrator $orchestrator,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai_site_audit.orchestrator'),
      $container->get('logger.channel.ai_site_audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data)) {
      $this->logger->error('Invalid queue item data for sitewide analysis.');
      return;
    }

    $tier = $data['tier'] ?? 'tier_2';
    $this->logger->info('Starting sitewide analysis from queue at tier @tier.', ['@tier' => $tier]);

    try {
      $result = $this->orchestrator->runAnalysis($tier);

      if (isset($result['error'])) {
        $this->logger->error('Sitewide analysis completed with error: @error', ['@error' => $result['error']]);

        // If this is a transient error, requeue.
        if (str_contains($result['error'], 'rate limit') || str_contains($result['error'], 'timeout')) {
          throw new RequeueException('Transient error — will retry: ' . $result['error']);
        }
        return;
      }

      $this->logger->info('Sitewide analysis completed successfully. Tier: @tier, Duration: @duration seconds.', [
        '@tier' => $tier,
        '@duration' => ($result['completed_at'] ?? time()) - ($result['started_at'] ?? time()),
      ]);
    }
    catch (RequeueException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Sitewide analysis queue worker failed: @message', ['@message' => $e->getMessage()]);

      // Suspend queue on persistent failures to avoid burning API budget.
      if (str_contains($e->getMessage(), 'API key') || str_contains($e->getMessage(), 'authentication')) {
        throw new SuspendQueueException('Persistent error — suspending queue: ' . $e->getMessage());
      }

      // Requeue for other errors.
      throw new RequeueException('Temporary failure — will retry: ' . $e->getMessage());
    }
  }

}
