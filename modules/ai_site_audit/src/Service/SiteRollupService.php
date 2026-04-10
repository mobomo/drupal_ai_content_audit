<?php

declare(strict_types=1);

namespace Drupal\ai_site_audit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Tier 2: Batched PHP JSON field rollup for sitewide statistics.
 *
 * Reads sub_scores, checkpoints, and action_items JSON columns from
 * ai_content_assessment entities. Supports incremental processing via
 * State API delta tracking to avoid re-scanning the entire table.
 *
 * Results are stored in KeyValue Expirable with a 1-hour TTL.
 */
class SiteRollupService {

  /**
   * The assessment table name.
   */
  protected const TABLE = 'ai_content_assessment';

  /**
   * KeyValue collection name.
   */
  protected const KV_COLLECTION = 'ai_site_audit';

  /**
   * KeyValue key for the rollup data.
   */
  protected const KV_KEY = 'sitewide_rollup';

  /**
   * Default rollup TTL in seconds (1 hour).
   */
  protected const DEFAULT_TTL = 3600;

  public function __construct(
    protected Connection $database,
    protected KeyValueExpirableFactoryInterface $keyValueFactory,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Compute the sitewide rollup, optionally forcing a full recomputation.
   *
   * @param bool $force_full
   *   If TRUE, discard cached rollup and recompute from scratch.
   *
   * @return array
   *   The complete rollup data structure.
   */
  public function computeRollup(bool $force_full = FALSE): array {
    if ($force_full) {
      return $this->fullRollup();
    }

    $cached = $this->getCachedRollup();
    if ($cached !== NULL && $this->getRecomputationNeeded() === 'none') {
      return $cached;
    }

    // If we have a cached rollup, do incremental; otherwise do full.
    if ($cached !== NULL) {
      return $this->incrementalRollup($cached);
    }

    return $this->fullRollup();
  }

  /**
   * Get the cached rollup data if it exists and hasn't expired.
   *
   * @return array|null
   *   The cached rollup data, or NULL if not available.
   */
  public function getCachedRollup(): ?array {
    $kv = $this->keyValueFactory->get(self::KV_COLLECTION);
    $data = $kv->get(self::KV_KEY);
    return is_array($data) ? $data : NULL;
  }

  /**
   * Determine whether recomputation is needed and what kind.
   *
   * @return string
   *   'none', 'incremental', or 'full'.
   */
  public function getRecomputationNeeded(): string {
    $cached = $this->getCachedRollup();
    if ($cached === NULL) {
      return 'full';
    }

    $newCount = $this->getNewAssessmentCount();
    if ($newCount === 0) {
      return 'none';
    }

    $config = $this->configFactory->get('ai_site_audit.settings');
    $threshold = (float) ($config->get('auto_analysis_threshold') ?: 0.10);
    $totalAssessed = $cached['total_assessed'] ?? 0;

    if ($totalAssessed > 0 && ($newCount / $totalAssessed) > $threshold) {
      return 'full';
    }

    return 'incremental';
  }

  /**
   * Get the count of new assessments since the last rollup.
   *
   * @return int
   *   Number of new assessment records.
   */
  public function getNewAssessmentCount(): int {
    $lastId = (int) $this->state->get('ai_site_audit.last_processed_assessment_id', 0);

    $count = (int) $this->database->select(self::TABLE, 'a')
      ->condition('a.id', $lastId, '>')
      ->countQuery()
      ->execute()
      ->fetchField();

    return $count;
  }

  /**
   * Perform an incremental rollup merging new data with existing rollup.
   *
   * @param array $existing
   *   The existing cached rollup data.
   *
   * @return array
   *   The updated rollup data.
   */
  protected function incrementalRollup(array $existing): array {
    $lastId = (int) $this->state->get('ai_site_audit.last_processed_assessment_id', 0);
    $config = $this->configFactory->get('ai_site_audit.settings');
    $batchSize = (int) ($config->get('rollup_batch_size') ?: 500);

    $this->logger->info('Starting incremental rollup from assessment ID @id', ['@id' => $lastId]);

    // Process new assessments in batches. We need the latest per node,
    // but for incremental we only process assessments newer than lastId.
    $query = $this->database->select(self::TABLE, 'a')
      ->fields('a', ['id', 'target_node', 'score', 'sub_scores', 'checkpoints', 'action_items'])
      ->condition('a.id', $lastId, '>')
      ->orderBy('a.id', 'ASC');

    $results = $query->execute();
    $processedCount = 0;
    $maxId = $lastId;

    // Accumulators for merging into existing rollup.
    $newSubScores = [];
    $newCheckpoints = [];
    $newActionItems = [];
    $newScores = [];

    while ($row = $results->fetchAssoc()) {
      $processedCount++;
      $maxId = max($maxId, (int) $row['id']);

      if ($row['score'] !== NULL) {
        $newScores[] = (int) $row['score'];
      }

      // Parse sub_scores.
      if (!empty($row['sub_scores'])) {
        $subScores = json_decode($row['sub_scores'], TRUE);
        if (is_array($subScores)) {
          foreach ($subScores as $sub) {
            $dim = $sub['dimension'] ?? 'unknown';
            if (!isset($newSubScores[$dim])) {
              $newSubScores[$dim] = ['total' => 0, 'count' => 0, 'max_possible' => $sub['max_score'] ?? 0, 'label' => $sub['label'] ?? $dim];
            }
            $newSubScores[$dim]['total'] += (float) ($sub['score'] ?? 0);
            $newSubScores[$dim]['count']++;
          }
        }
      }

      // Parse checkpoints.
      if (!empty($row['checkpoints'])) {
        $checkpoints = json_decode($row['checkpoints'], TRUE);
        if (is_array($checkpoints)) {
          foreach ($checkpoints as $cp) {
            $item = $cp['item'] ?? 'unknown';
            $status = $cp['status'] ?? 'unknown';
            if (!isset($newCheckpoints[$item])) {
              $newCheckpoints[$item] = ['pass' => 0, 'fail' => 0, 'warning' => 0, 'category' => $cp['category'] ?? '', 'priority' => $cp['priority'] ?? 'medium'];
            }
            if (isset($newCheckpoints[$item][$status])) {
              $newCheckpoints[$item][$status]++;
            }
          }
        }
      }

      // Parse action items.
      if (!empty($row['action_items'])) {
        $actionItems = json_decode($row['action_items'], TRUE);
        if (is_array($actionItems)) {
          foreach ($actionItems as $ai) {
            $title = $ai['title'] ?? 'unknown';
            if (!isset($newActionItems[$title])) {
              $newActionItems[$title] = ['count' => 0, 'priority' => $ai['priority'] ?? 'medium'];
            }
            $newActionItems[$title]['count']++;
          }
        }
      }
    }

    // Merge with existing rollup.
    $rollup = $this->mergeRollup($existing, $newSubScores, $newCheckpoints, $newActionItems, $newScores, $processedCount);
    $rollup['computed_at'] = time();
    $rollup['last_assessment_id'] = $maxId;

    // Store result.
    $this->storeRollup($rollup);

    // Update state.
    $this->state->set('ai_site_audit.last_processed_assessment_id', $maxId);
    $this->state->set('ai_site_audit.last_rollup_time', time());

    $this->logger->info('Incremental rollup complete. Processed @count new assessments.', ['@count' => $processedCount]);

    return $rollup;
  }

  /**
   * Perform a full rollup from scratch, processing all assessments.
   *
   * @return array
   *   The complete rollup data structure.
   */
  protected function fullRollup(): array {
    $config = $this->configFactory->get('ai_site_audit.settings');
    $batchSize = (int) ($config->get('rollup_batch_size') ?: 500);

    $this->logger->info('Starting full rollup of all assessments.');

    // Build the latest-per-node subquery.
    $subquery = $this->database->select(self::TABLE, 'sub');
    $subquery->addField('sub', 'target_node');
    $subquery->addExpression('MAX(sub.id)', 'max_id');
    $subquery->groupBy('sub.target_node');

    // Query all latest assessments with JSON fields.
    $query = $this->database->select(self::TABLE, 'a');
    $query->join($subquery, 'latest', 'a.id = latest.max_id');
    $query->fields('a', ['id', 'target_node', 'score', 'sub_scores', 'checkpoints', 'action_items']);
    $query->orderBy('a.id', 'ASC');

    $results = $query->execute();
    $totalAssessed = 0;
    $maxId = 0;
    $scoreSum = 0;

    // Accumulators.
    $subScoreAccum = [];
    $checkpointAccum = [];
    $actionItemAccum = [];
    $scoreDistribution = ['0-19' => 0, '20-39' => 0, '40-59' => 0, '60-79' => 0, '80-100' => 0];
    $byContentType = [];
    $checkpointTotals = ['pass' => 0, 'fail' => 0, 'warning' => 0];

    while ($row = $results->fetchAssoc()) {
      $totalAssessed++;
      $maxId = max($maxId, (int) $row['id']);
      $score = $row['score'] !== NULL ? (int) $row['score'] : NULL;

      if ($score !== NULL) {
        $scoreSum += $score;

        // Score distribution.
        if ($score <= 19) {
          $scoreDistribution['0-19']++;
        }
        elseif ($score <= 39) {
          $scoreDistribution['20-39']++;
        }
        elseif ($score <= 59) {
          $scoreDistribution['40-59']++;
        }
        elseif ($score <= 79) {
          $scoreDistribution['60-79']++;
        }
        else {
          $scoreDistribution['80-100']++;
        }
      }

      // Parse sub_scores.
      if (!empty($row['sub_scores'])) {
        $subScores = json_decode($row['sub_scores'], TRUE);
        if (is_array($subScores)) {
          foreach ($subScores as $sub) {
            $dim = $sub['dimension'] ?? 'unknown';
            if (!isset($subScoreAccum[$dim])) {
              $subScoreAccum[$dim] = [
                'total' => 0,
                'count' => 0,
                'max_possible' => (int) ($sub['max_score'] ?? 0),
                'label' => $sub['label'] ?? $dim,
              ];
            }
            $subScoreAccum[$dim]['total'] += (float) ($sub['score'] ?? 0);
            $subScoreAccum[$dim]['count']++;
          }
        }
      }

      // Parse checkpoints.
      if (!empty($row['checkpoints'])) {
        $checkpoints = json_decode($row['checkpoints'], TRUE);
        if (is_array($checkpoints)) {
          foreach ($checkpoints as $cp) {
            $item = $cp['item'] ?? 'unknown';
            $status = $cp['status'] ?? 'unknown';

            if (!isset($checkpointAccum[$item])) {
              $checkpointAccum[$item] = [
                'pass' => 0,
                'fail' => 0,
                'warning' => 0,
                'category' => $cp['category'] ?? '',
                'priority' => $cp['priority'] ?? 'medium',
              ];
            }
            if (isset($checkpointAccum[$item][$status])) {
              $checkpointAccum[$item][$status]++;
            }
            if (isset($checkpointTotals[$status])) {
              $checkpointTotals[$status]++;
            }
          }
        }
      }

      // Parse action items.
      if (!empty($row['action_items'])) {
        $actionItems = json_decode($row['action_items'], TRUE);
        if (is_array($actionItems)) {
          foreach ($actionItems as $ai) {
            $title = $ai['title'] ?? 'unknown';
            if (!isset($actionItemAccum[$title])) {
              $actionItemAccum[$title] = ['count' => 0, 'priority' => $ai['priority'] ?? 'medium'];
            }
            $actionItemAccum[$title]['count']++;
          }
        }
      }
    }

    // Build sub-score averages.
    $subScoreAverages = [];
    foreach ($subScoreAccum as $dim => $data) {
      $avg = $data['count'] > 0 ? round($data['total'] / $data['count'], 2) : 0;
      $pct = $data['max_possible'] > 0 ? round(($avg / $data['max_possible']) * 100, 2) : 0;
      $subScoreAverages[$dim] = [
        'label' => $data['label'],
        'avg' => $avg,
        'max_possible' => $data['max_possible'],
        'pct' => $pct,
      ];
    }

    // Build top failing checkpoints (sorted by fail_count DESC, top 20).
    $failingCheckpoints = [];
    foreach ($checkpointAccum as $item => $counts) {
      if ($counts['fail'] > 0 || $counts['warning'] > 0) {
        $totalChecked = $counts['pass'] + $counts['fail'] + $counts['warning'];
        $failingCheckpoints[] = [
          'item' => $item,
          'category' => $counts['category'],
          'priority' => $counts['priority'],
          'fail_count' => $counts['fail'],
          'warning_count' => $counts['warning'],
          'pct' => $totalAssessed > 0 ? round(($counts['fail'] / $totalAssessed) * 100, 1) : 0,
        ];
      }
    }
    usort($failingCheckpoints, fn($a, $b) => $b['fail_count'] <=> $a['fail_count']);
    $failingCheckpoints = array_slice($failingCheckpoints, 0, 20);

    // Build top action items (sorted by count DESC, top 20).
    $topActionItems = [];
    foreach ($actionItemAccum as $title => $data) {
      $topActionItems[] = [
        'title' => $title,
        'priority' => $data['priority'],
        'count' => $data['count'],
      ];
    }
    usort($topActionItems, fn($a, $b) => $b['count'] <=> $a['count']);
    $topActionItems = array_slice($topActionItems, 0, 20);

    // Compute coverage.
    $totalPublished = (int) $this->database->select('node_field_data', 'n')
      ->condition('n.status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
    $coveragePct = $totalPublished > 0 ? round(($totalAssessed / $totalPublished) * 100, 1) : 0;

    $rollup = [
      'computed_at' => time(),
      'last_assessment_id' => $maxId,
      'total_assessed' => $totalAssessed,
      'coverage_pct' => $coveragePct,
      'avg_score' => $totalAssessed > 0 ? round($scoreSum / $totalAssessed, 1) : 0,
      'score_distribution' => $scoreDistribution,
      'by_content_type' => $byContentType,
      'sub_score_averages' => $subScoreAverages,
      'top_failing_checkpoints' => $failingCheckpoints,
      'top_action_items' => $topActionItems,
      'checkpoint_status_totals' => $checkpointTotals,
    ];

    // Store result.
    $this->storeRollup($rollup);

    // Update state.
    $this->state->set('ai_site_audit.last_processed_assessment_id', $maxId);
    $this->state->set('ai_site_audit.last_rollup_time', time());

    $this->logger->info('Full rollup complete. Processed @count assessments.', ['@count' => $totalAssessed]);

    return $rollup;
  }

  /**
   * Merge new incremental data into existing rollup.
   */
  protected function mergeRollup(array $existing, array $newSubScores, array $newCheckpoints, array $newActionItems, array $newScores, int $newCount): array {
    $rollup = $existing;

    // Update total assessed — note: incremental may include re-assessments of same node,
    // so this is approximate until next full rollup.
    $rollup['total_assessed'] = ($existing['total_assessed'] ?? 0) + $newCount;

    // Update average score.
    if (!empty($newScores)) {
      $existingTotal = ($existing['avg_score'] ?? 0) * ($existing['total_assessed'] ?? 0);
      $newTotal = array_sum($newScores);
      $rollup['avg_score'] = $rollup['total_assessed'] > 0
        ? round(($existingTotal + $newTotal) / $rollup['total_assessed'], 1)
        : 0;
    }

    // Merge sub_score_averages.
    $existingSubs = $existing['sub_score_averages'] ?? [];
    foreach ($newSubScores as $dim => $data) {
      if (isset($existingSubs[$dim])) {
        $oldTotal = $existingSubs[$dim]['avg'] * ($existing['total_assessed'] ?? 1);
        $combined = $oldTotal + $data['total'];
        $combinedCount = ($existing['total_assessed'] ?? 0) + $data['count'];
        $avg = $combinedCount > 0 ? round($combined / $combinedCount, 2) : 0;
        $rollup['sub_score_averages'][$dim]['avg'] = $avg;
        $pct = $data['max_possible'] > 0 ? round(($avg / $data['max_possible']) * 100, 2) : 0;
        $rollup['sub_score_averages'][$dim]['pct'] = $pct;
      }
      else {
        $avg = $data['count'] > 0 ? round($data['total'] / $data['count'], 2) : 0;
        $rollup['sub_score_averages'][$dim] = [
          'label' => $data['label'],
          'avg' => $avg,
          'max_possible' => $data['max_possible'],
          'pct' => $data['max_possible'] > 0 ? round(($avg / $data['max_possible']) * 100, 2) : 0,
        ];
      }
    }

    // Merge top_failing_checkpoints — add new counts.
    $existingCheckpoints = [];
    foreach ($existing['top_failing_checkpoints'] ?? [] as $cp) {
      $existingCheckpoints[$cp['item']] = $cp;
    }
    foreach ($newCheckpoints as $item => $counts) {
      if (isset($existingCheckpoints[$item])) {
        $existingCheckpoints[$item]['fail_count'] += $counts['fail'];
        $existingCheckpoints[$item]['warning_count'] = ($existingCheckpoints[$item]['warning_count'] ?? 0) + $counts['warning'];
      }
      elseif ($counts['fail'] > 0 || $counts['warning'] > 0) {
        $existingCheckpoints[$item] = [
          'item' => $item,
          'category' => $counts['category'],
          'priority' => $counts['priority'],
          'fail_count' => $counts['fail'],
          'warning_count' => $counts['warning'],
          'pct' => 0,
        ];
      }
    }
    // Re-sort and limit.
    $merged = array_values($existingCheckpoints);
    usort($merged, fn($a, $b) => $b['fail_count'] <=> $a['fail_count']);
    $rollup['top_failing_checkpoints'] = array_slice($merged, 0, 20);

    // Merge top_action_items.
    $existingActions = [];
    foreach ($existing['top_action_items'] ?? [] as $ai) {
      $existingActions[$ai['title']] = $ai;
    }
    foreach ($newActionItems as $title => $data) {
      if (isset($existingActions[$title])) {
        $existingActions[$title]['count'] += $data['count'];
      }
      else {
        $existingActions[$title] = ['title' => $title, 'priority' => $data['priority'], 'count' => $data['count']];
      }
    }
    $mergedActions = array_values($existingActions);
    usort($mergedActions, fn($a, $b) => $b['count'] <=> $a['count']);
    $rollup['top_action_items'] = array_slice($mergedActions, 0, 20);

    // Update checkpoint totals.
    $existingTotals = $existing['checkpoint_status_totals'] ?? ['pass' => 0, 'fail' => 0, 'warning' => 0];
    foreach ($newCheckpoints as $counts) {
      $existingTotals['pass'] += $counts['pass'];
      $existingTotals['fail'] += $counts['fail'];
      $existingTotals['warning'] += $counts['warning'];
    }
    $rollup['checkpoint_status_totals'] = $existingTotals;

    return $rollup;
  }

  /**
   * Store the rollup in KeyValue Expirable.
   */
  protected function storeRollup(array $rollup): void {
    $config = $this->configFactory->get('ai_site_audit.settings');
    $ttl = (int) ($config->get('rollup_max_age_hours') ?: 1) * 3600;

    $kv = $this->keyValueFactory->get(self::KV_COLLECTION);
    $kv->setWithExpire(self::KV_KEY, $rollup, $ttl);
  }

}
