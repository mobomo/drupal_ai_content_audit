<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Commands;

use Drupal\ai_content_audit\Service\AiAssessmentService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drush\Attributes\Command;
use Drush\Attributes\Help;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for AI Content Audit module.
 *
 * Usage:
 *   drush ai_content_audit:assess --nid=42
 *   drush ai_content_audit:assess --all
 *   drush ai_content_audit:assess --all --type=article
 *   drush ai_content_audit:purge
 *   drush ai_content_audit:reinstall
 */
final class AiContentAuditCommands extends DrushCommands {

  public function __construct(
    private readonly AiAssessmentService $assessmentService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleInstallerInterface $moduleInstaller,
  ) {
    parent::__construct();
  }

  /**
   * Assess a single node synchronously or enqueue all nodes for bulk assessment.
   */
  #[Command(name: 'ai_content_audit:assess', aliases: ['aca'])]
  #[Help(description: 'Run AI content assessment on one node (sync) or enqueue all eligible nodes (async queue).')]
  #[Option(name: 'nid', description: 'Node ID to assess synchronously.')]
  #[Option(name: 'all', description: 'Enqueue ALL published nodes of the configured types for background assessment via cron.')]
  #[Option(name: 'type', description: 'Limit bulk enqueue to a specific node type machine name (e.g. article). Requires --all.')]
  #[Usage(name: 'drush aca --nid=42', description: 'Assess node 42 synchronously.')]
  #[Usage(name: 'drush aca --all', description: 'Enqueue all configured node types for assessment.')]
  #[Usage(name: 'drush aca --all --type=article', description: 'Enqueue all published article nodes.')]
  public function assess(
    array $options = [
      'nid'  => self::OPT,
      'all'  => FALSE,
      'type' => self::OPT,
    ],
  ): void {
    if ($options['nid']) {
      $this->assessSingleNode((int) $options['nid']);
      return;
    }

    if ($options['all']) {
      $this->enqueueBulk($options['type'] ?? NULL);
      return;
    }

    $this->io()->error('Provide --nid=<id> for a single node, or --all to enqueue all eligible nodes.');
    $this->io()->text('Examples:');
    $this->io()->listing([
      'drush aca --nid=42',
      'drush aca --all',
      'drush aca --all --type=article',
    ]);
    throw new \RuntimeException('No valid option provided. Use --nid or --all.');
  }

  /**
   * @deprecated The site-wide audit pipeline has been removed. Command retained
   *   only to provide a helpful runtime error if invoked on older tooling.
   */
  #[Command(name: 'ai_content_audit:site-audit', aliases: ['acsa'])]
  #[Help(description: 'This command has been removed. Use `drush ai_content_audit:assess --all` for bulk node assessment.')]
  public function siteAudit(): void {
    throw new \RuntimeException('The site-wide audit command has moved to the ai_site_audit submodule. Use `drush ai_site_audit:analyze` for sitewide analysis, or `drush ai_content_audit:assess --all` to enqueue individual node assessments.');
  }

  /**
   * Purge all ai_content_assessment entities to allow safe module uninstall.
   */
  #[Command(name: 'ai_content_audit:purge', aliases: ['aca:purge'])]
  #[Help(description: 'Delete all AI Content Assessment entities, enabling safe module uninstall.')]
  #[Usage(name: 'drush aca:purge', description: 'Purge all assessment entities (prompts for confirmation).')]
  #[Usage(name: 'drush aca:purge --yes', description: 'Purge all assessment entities without prompting.')]
  public function purge(): void {
    $deleted = $this->doPurge();
    if ($deleted === NULL) {
      // Cancelled or nothing to do — doPurge() already output the message.
      return;
    }
    $this->io()->success(sprintf('Purged %d AI Content Assessment entity(ies).', $deleted));
  }

  /**
   * Purge all assessments, uninstall, and re-enable the module (dev workflow).
   */
  #[Command(name: 'ai_content_audit:reinstall', aliases: ['aca:reinstall'])]
  #[Help(description: 'Purge all assessments, uninstall, and re-enable ai_content_audit (dev convenience).')]
  #[Usage(name: 'drush aca:reinstall', description: 'Full purge + uninstall + reinstall of the ai_content_audit module.')]
  public function reinstall(): void {
    $this->io()->section('Step 1/4: Purging all AI Content Assessment entities…');
    $deleted = $this->doPurge();
    if ($deleted === NULL) {
      // User cancelled — abort the reinstall.
      return;
    }
    $this->io()->text(sprintf('Purged %d entity(ies).', $deleted));

    $this->io()->section('Step 2/4: Uninstalling ai_content_audit…');
    $this->moduleInstaller->uninstall(['ai_content_audit']);
    $this->io()->text('Module uninstalled.');

    $this->io()->section('Step 3/4: Re-enabling ai_content_audit…');
    $this->moduleInstaller->install(['ai_content_audit']);
    $this->io()->text('Module re-enabled.');

    $this->io()->section('Step 4/4: Clearing all caches…');
    drupal_flush_all_caches();

    $this->io()->success('ai_content_audit reinstalled successfully.');
  }

  // ── Private helpers ────────────────────────────────────────────────────────

  /**
   * Deletes all ai_content_assessment entities in batches.
   *
   * Prompts for confirmation before deleting. Returns the number of entities
   * deleted on success, or NULL if the operation was cancelled or there was
   * nothing to delete.
   */
  private function doPurge(): ?int {
    $storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    $count = count($ids);

    if ($count === 0) {
      $this->io()->success('No AI Content Assessment entities found. Nothing to purge.');
      return NULL;
    }

    if (!$this->io()->confirm(sprintf('This will delete %d assessment(s). Continue?', $count))) {
      $this->io()->text('Purge cancelled.');
      return NULL;
    }

    $batch_size = 50;
    $deleted = 0;

    $this->io()->progressStart($count);

    foreach (array_chunk($ids, $batch_size) as $chunk) {
      $entities = $storage->loadMultiple($chunk);
      $storage->delete($entities);
      $deleted += count($entities);
      $this->io()->progressAdvance(count($entities));
    }

    $this->io()->progressFinish();

    return $deleted;
  }

  /**
   * Runs an AI assessment on a single node synchronously.
   */
  private function assessSingleNode(int $nid): void {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node) {
      $this->io()->error(sprintf('Node %d not found.', $nid));
      throw new \RuntimeException(sprintf('Node %d not found.', $nid));
    }

    $this->io()->text(sprintf('Assessing node %d: %s', $nid, $node->label()));
    $result = $this->assessmentService->assessNode($node);

    if ($result['success']) {
      $score = $result['parsed']['ai_readiness_score'] ?? 'N/A';
      $this->io()->success(sprintf('Assessment complete for node %d. Score: %s/100', $nid, $score));
    }
    else {
      $error = $result['error'] ?? 'Unknown error';
      $this->io()->error(sprintf('Assessment failed for node %d: %s', $nid, $error));
      throw new \RuntimeException(sprintf('Assessment failed for node %d: %s', $nid, $error));
    }
  }

  /**
   * Enqueues all eligible published nodes for background assessment.
   */
  private function enqueueBulk(?string $type = NULL): void {
    $config           = $this->configFactory->get('ai_content_audit.settings');
    $configured_types = $config->get('node_types') ?? [];
    $storage          = $this->entityTypeManager->getStorage('node');

    // Resolve the list of node types to process.
    if ($type) {
      $types = [$type];
    }
    elseif (!empty($configured_types)) {
      $types = $configured_types;
    }
    else {
      // No configured types → process all node types.
      $types = array_keys(
        $this->entityTypeManager->getStorage('node_type')->loadMultiple()
      );
    }

    // Count total nodes first for an accurate progress bar.
    $total = 0;
    foreach ($types as $node_type) {
      $count = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $node_type)
        ->condition('status', 1)
        ->count()
        ->execute();
      $total += $count;
    }

    if ($total === 0) {
      $this->io()->warning('No published nodes found for the specified type(s): ' . implode(', ', $types));
      throw new \RuntimeException('No published nodes found for the specified type(s): ' . implode(', ', $types));
    }

    $this->io()->text(sprintf('Enqueueing %d node(s) across type(s): %s', $total, implode(', ', $types)));

    $queue  = $this->queueFactory->get('ai_content_audit_assessment');
    $queued = 0;

    $this->io()->progressStart($total);

    // M-5: Process nodes in chunks to avoid exhausting PHP memory on large
    // sites.  Each iteration fetches at most $chunk_size node IDs.
    $chunk_size = 50;
    foreach ($types as $node_type) {
      $offset = 0;
      do {
        $nids = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', $node_type)
          ->condition('status', 1)
          ->range($offset, $chunk_size)
          ->execute();

        foreach ($nids as $nid) {
          $queue->createItem(['nid' => (int) $nid]);
          $queued++;
          $this->io()->progressAdvance();
        }

        $offset += $chunk_size;
      } while (count($nids) === $chunk_size);
    }

    $this->io()->progressFinish();
    $this->io()->success(sprintf(
      '%d node(s) enqueued successfully. Process with: drush queue:run ai_content_audit_assessment',
      $queued
    ));
  }

}
