<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Commands;

use Drupal\ai_content_audit\Service\AiAssessmentService;
use Drupal\ai_content_audit\Service\ProviderModelChoices;
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
 *   drush ai_content_audit:assess --nid=42 --provider=anthropic --model=claude-3-5-sonnet-20241022
 *   drush ai_content_audit:assess --all
 *   drush ai_content_audit:assess --all --type=article --provider=openai --model=gpt-4o
 *   drush ai_content_audit:providers
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
    private readonly ProviderModelChoices $providerModelChoices,
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
  #[Option(name: 'provider', description: 'AI provider plugin ID to use (e.g. openai, anthropic). Overrides the configured default.')]
  #[Option(name: 'model', description: 'AI model ID to use (e.g. gpt-4o, claude-3-5-sonnet-20241022). Overrides the configured default.')]
  #[Usage(name: 'drush aca --nid=42', description: 'Assess node 42 using the configured default provider.')]
  #[Usage(name: 'drush aca --nid=42 --provider=anthropic --model=claude-3-5-sonnet-20241022', description: 'Assess node 42 using Anthropic Claude.')]
  #[Usage(name: 'drush aca --all', description: 'Enqueue all configured node types for assessment.')]
  #[Usage(name: 'drush aca --all --type=article --provider=openai --model=gpt-4o', description: 'Enqueue all article nodes using OpenAI GPT-4o.')]
  public function assess(
    array $options = [
      'nid'      => self::OPT,
      'all'      => FALSE,
      'type'     => self::OPT,
      'provider' => self::OPT,
      'model'    => self::OPT,
    ],
  ): void {
    // Build runtime AI options from CLI flags (empty values are ignored
    // downstream — the service falls back to configured defaults).
    $ai_options = [];
    if (!empty($options['provider'])) {
      $ai_options['provider_id'] = $options['provider'];
    }
    if (!empty($options['model'])) {
      $ai_options['model_id'] = $options['model'];
    }

    if ($options['nid']) {
      $this->assessSingleNode((int) $options['nid'], $ai_options);
      return;
    }

    if ($options['all']) {
      $this->enqueueBulk($options['type'] ?? NULL, $ai_options);
      return;
    }

    $this->io()->error('Provide --nid=<id> for a single node, or --all to enqueue all eligible nodes.');
    $this->io()->text('Examples:');
    $this->io()->listing([
      'drush aca --nid=42',
      'drush aca --nid=42 --provider=anthropic --model=claude-3-5-sonnet-20241022',
      'drush aca --all',
      'drush aca --all --type=article',
    ]);
    throw new \RuntimeException('No valid option provided. Use --nid or --all.');
  }

  /**
   * List all enabled AI providers and their configured models for chat.
   */
  #[Command(name: 'ai-content-audit:providers', aliases: ['acap'])]
  #[Help(description: 'List all enabled AI providers and their configured models available for content audit.')]
  #[Usage(name: 'drush acap', description: 'Print a table of provider / model pairs for the chat operation type.')]
  public function listProviders(): void {
    $choices = $this->providerModelChoices->forOperationType('chat');

    if (empty($choices)) {
      $this->io()->warning('No configured AI chat providers found. Install and configure a provider module first.');
      return;
    }

    // Build table rows.
    $rows = [];
    foreach ($choices as $choice) {
      $rows[] = [
        $choice['provider_id'],
        $choice['model_id'],
        $choice['label'],
        $choice['key'],
      ];
    }

    $this->io()->table(
      ['Provider ID', 'Model ID', 'Label', 'Composite key (provider__model)'],
      $rows,
    );

    // Print the currently configured module-level default.
    $config        = $this->configFactory->get('ai_content_audit.settings');
    $def_provider  = $config->get('default_provider') ?: '(global default)';
    $def_model     = $config->get('default_model') ?: '(global default)';
    $this->io()->note(sprintf(
      'Module default → provider: %s | model: %s',
      $def_provider,
      $def_model,
    ));
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
   *
   * @param int $nid
   *   Node ID to assess.
   * @param array $ai_options
   *   Optional AI overrides passed to AiAssessmentService::assessNode().
   *   Supported keys: 'provider_id', 'model_id'.
   */
  private function assessSingleNode(int $nid, array $ai_options = []): void {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node) {
      $this->io()->error(sprintf('Node %d not found.', $nid));
      throw new \RuntimeException(sprintf('Node %d not found.', $nid));
    }

    $provider_label = !empty($ai_options['provider_id'])
      ? sprintf(' [provider: %s, model: %s]', $ai_options['provider_id'], $ai_options['model_id'] ?? 'default')
      : '';

    $this->io()->text(sprintf('Assessing node %d: %s%s', $nid, $node->label(), $provider_label));
    $result = $this->assessmentService->assessNode($node, $ai_options);

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
   *
   * @param string|null $type
   *   Optional node type to limit enqueue to.
   * @param array $ai_options
   *   Optional AI overrides to embed in each queue item.
   *   Supported keys: 'provider_id', 'model_id'.
   *   The queue worker passes these to AiAssessmentService::assessNode().
   */
  private function enqueueBulk(?string $type = NULL, array $ai_options = []): void {
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

    // Process nodes in chunks to avoid exhausting PHP memory on large sites.
    // Each iteration fetches at most $chunk_size node IDs.
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
          // Embed ai_options in the queue item so the worker can honour them.
          $item = ['nid' => (int) $nid];
          if (!empty($ai_options)) {
            $item['options'] = $ai_options;
          }
          $queue->createItem($item);
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
