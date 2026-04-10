<?php

declare(strict_types=1);

namespace Drupal\ai_site_audit\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Psr\Log\LoggerInterface;

/**
 * Tier 1: SQL-based aggregation service for sitewide content audit stats.
 *
 * All queries use the "latest assessment per node" pattern via a subquery:
 *   SELECT target_node, MAX(id) FROM ai_content_assessment GROUP BY target_node
 *
 * Results are cached with tag 'ai_content_assessment_list' for auto-invalidation
 * when new assessments are created.
 */
class SiteAggregationService {

  /**
   * Default cache max-age in seconds.
   */
  protected const DEFAULT_CACHE_MAX_AGE = 300;

  /**
   * The assessment table name.
   */
  protected const TABLE = 'ai_content_assessment';

  public function __construct(
    protected Connection $database,
    protected CacheBackendInterface $cache,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Get overall assessment statistics.
   *
   * @return array
   *   Array with keys: avg_score, median_score, total_assessed, ai_ready,
   *   improving, needs_work, min_score, max_score.
   */
  public function getOverallStats(): array {
    return $this->getCachedOrCompute('ai_site_audit:overall_stats', function () {
      $latest = $this->latestAssessmentSubquery();

      $query = $this->database->select(self::TABLE, 'a');
      $query->join($latest, 'latest', 'a.id = latest.max_id');
      $query->addExpression('AVG(a.score)', 'avg_score');
      $query->addExpression('COUNT(*)', 'total_assessed');
      $query->addExpression("COUNT(CASE WHEN a.score >= 80 THEN 1 END)", 'ai_ready');
      $query->addExpression("COUNT(CASE WHEN a.score >= 50 AND a.score < 80 THEN 1 END)", 'improving');
      $query->addExpression("COUNT(CASE WHEN a.score < 50 THEN 1 END)", 'needs_work');
      $query->addExpression('MIN(a.score)', 'min_score');
      $query->addExpression('MAX(a.score)', 'max_score');

      $result = $query->execute()->fetchAssoc();

      // Compute median separately (SQL doesn't have a portable MEDIAN function).
      $median = $this->computeMedianScore();

      return [
        'avg_score' => $result['avg_score'] !== NULL ? round((float) $result['avg_score'], 1) : NULL,
        'median_score' => $median,
        'total_assessed' => (int) ($result['total_assessed'] ?? 0),
        'ai_ready' => (int) ($result['ai_ready'] ?? 0),
        'improving' => (int) ($result['improving'] ?? 0),
        'needs_work' => (int) ($result['needs_work'] ?? 0),
        'min_score' => $result['min_score'] !== NULL ? (int) $result['min_score'] : NULL,
        'max_score' => $result['max_score'] !== NULL ? (int) $result['max_score'] : NULL,
      ];
    });
  }

  /**
   * Get score distribution across defined buckets.
   *
   * @return array
   *   Associative array: ['0-19' => count, '20-39' => count, ...].
   */
  public function getScoreDistribution(): array {
    return $this->getCachedOrCompute('ai_site_audit:score_distribution', function () {
      $latest = $this->latestAssessmentSubquery();

      $query = $this->database->select(self::TABLE, 'a');
      $query->join($latest, 'latest', 'a.id = latest.max_id');
      $query->addExpression("COUNT(CASE WHEN a.score BETWEEN 0 AND 19 THEN 1 END)", 'bucket_0_19');
      $query->addExpression("COUNT(CASE WHEN a.score BETWEEN 20 AND 39 THEN 1 END)", 'bucket_20_39');
      $query->addExpression("COUNT(CASE WHEN a.score BETWEEN 40 AND 59 THEN 1 END)", 'bucket_40_59');
      $query->addExpression("COUNT(CASE WHEN a.score BETWEEN 60 AND 79 THEN 1 END)", 'bucket_60_79');
      $query->addExpression("COUNT(CASE WHEN a.score BETWEEN 80 AND 100 THEN 1 END)", 'bucket_80_100');

      $result = $query->execute()->fetchAssoc();

      return [
        '0-19' => (int) ($result['bucket_0_19'] ?? 0),
        '20-39' => (int) ($result['bucket_20_39'] ?? 0),
        '40-59' => (int) ($result['bucket_40_59'] ?? 0),
        '60-79' => (int) ($result['bucket_60_79'] ?? 0),
        '80-100' => (int) ($result['bucket_80_100'] ?? 0),
      ];
    });
  }

  /**
   * Get per-content-type breakdown of assessment scores.
   *
   * @return array
   *   Array of arrays with keys: type, label, count, avg_score, min_score, max_score.
   */
  public function getContentTypeBreakdown(): array {
    return $this->getCachedOrCompute('ai_site_audit:content_type_breakdown', function () {
      $latest = $this->latestAssessmentSubquery();

      $query = $this->database->select(self::TABLE, 'a');
      $query->join($latest, 'latest', 'a.id = latest.max_id');
      $query->join('node_field_data', 'n', 'a.target_node = n.nid');
      $query->addField('n', 'type');
      $query->addExpression('COUNT(*)', 'count');
      $query->addExpression('AVG(a.score)', 'avg_score');
      $query->addExpression('MIN(a.score)', 'min_score');
      $query->addExpression('MAX(a.score)', 'max_score');
      $query->groupBy('n.type');
      $query->orderBy('avg_score', 'ASC');

      $results = $query->execute()->fetchAll();

      // Load content type labels.
      $types = [];
      foreach ($results as $row) {
        $types[] = [
          'type' => $row->type,
          'label' => ucfirst(str_replace('_', ' ', $row->type)),
          'count' => (int) $row->count,
          'avg_score' => round((float) $row->avg_score, 1),
          'min_score' => (int) $row->min_score,
          'max_score' => (int) $row->max_score,
        ];
      }

      return $types;
    });
  }

  /**
   * Get coverage statistics comparing assessed vs total published nodes.
   *
   * @return array
   *   Array with keys: total_published, total_assessed, coverage_pct, unassessed_count.
   */
  public function getCoverageStats(): array {
    return $this->getCachedOrCompute('ai_site_audit:coverage_stats', function () {
      // Count published nodes.
      $totalPublished = (int) $this->database->select('node_field_data', 'n')
        ->condition('n.status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      // Count distinct assessed nodes using latest assessment subquery.
      $latest = $this->latestAssessmentSubquery();
      $query = $this->database->select(self::TABLE, 'a');
      $query->join($latest, 'latest', 'a.id = latest.max_id');
      $totalAssessed = (int) $query->countQuery()->execute()->fetchField();

      $coveragePct = $totalPublished > 0
        ? round(($totalAssessed / $totalPublished) * 100, 1)
        : 0;

      return [
        'total_published' => $totalPublished,
        'total_assessed' => $totalAssessed,
        'coverage_pct' => $coveragePct,
        'unassessed_count' => $totalPublished - $totalAssessed,
      ];
    });
  }

  /**
   * Get score trend over time periods.
   *
   * @param string $interval
   *   Time interval: 'day', 'week', 'month'.
   * @param int $periods
   *   Number of periods to retrieve.
   *
   * @return array
   *   Array of arrays with keys: period, avg_score, count.
   */
  public function getScoreTrend(string $interval = 'week', int $periods = 12): array {
    return $this->getCachedOrCompute("ai_site_audit:score_trend:{$interval}:{$periods}", function () use ($interval, $periods) {
      $seconds = match ($interval) {
        'day' => 86400,
        'week' => 604800,
        'month' => 2592000,
        default => 604800,
      };

      $cutoff = time() - ($seconds * $periods);

      $query = $this->database->select(self::TABLE, 'a');
      $query->condition('a.created', $cutoff, '>=');
      $query->addExpression("FLOOR(a.created / :seconds)", 'period_bucket', [':seconds' => $seconds]);
      $query->addExpression('AVG(a.score)', 'avg_score');
      $query->addExpression('COUNT(*)', 'count');
      $query->groupBy('period_bucket');
      $query->orderBy('period_bucket', 'ASC');

      $results = $query->execute()->fetchAll();

      $trend = [];
      foreach ($results as $row) {
        $trend[] = [
          'period' => (int) $row->period_bucket * $seconds,
          'avg_score' => round((float) $row->avg_score, 1),
          'count' => (int) $row->count,
        ];
      }

      return $trend;
    });
  }

  /**
   * Get top or bottom performing nodes by score.
   *
   * @param int $limit
   *   Number of nodes to retrieve.
   * @param string $sort
   *   Sort direction: 'worst' (ASC) or 'best' (DESC).
   *
   * @return array
   *   Array of arrays with keys: nid, title, score, type, created.
   */
  public function getTopBottomNodes(int $limit = 10, string $sort = 'worst'): array {
    $cid = "ai_site_audit:top_bottom_nodes:{$sort}:{$limit}";
    return $this->getCachedOrCompute($cid, function () use ($limit, $sort) {
      $latest = $this->latestAssessmentSubquery();
      $direction = $sort === 'best' ? 'DESC' : 'ASC';

      $query = $this->database->select(self::TABLE, 'a');
      $query->join($latest, 'latest', 'a.id = latest.max_id');
      $query->join('node_field_data', 'n', 'a.target_node = n.nid');
      $query->addField('a', 'target_node', 'nid');
      $query->addField('n', 'title');
      $query->addField('a', 'score');
      $query->addField('n', 'type');
      $query->addField('a', 'created');
      $query->orderBy('a.score', $direction);
      $query->range(0, $limit);

      $results = $query->execute()->fetchAll();

      $nodes = [];
      foreach ($results as $row) {
        $nodes[] = [
          'nid' => (int) $row->nid,
          'title' => $row->title,
          'score' => (int) $row->score,
          'type' => $row->type,
          'created' => (int) $row->created,
        ];
      }

      return $nodes;
    });
  }

  /**
   * Invalidate all cached aggregation data.
   */
  public function invalidateCache(): void {
    $this->cache->invalidateAll();
  }

  /**
   * Builds the "latest assessment per node" subquery.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   A subquery selecting target_node and MAX(id) as max_id.
   */
  protected function latestAssessmentSubquery(): SelectInterface {
    $subquery = $this->database->select(self::TABLE, 'sub');
    $subquery->addField('sub', 'target_node');
    $subquery->addExpression('MAX(sub.id)', 'max_id');
    $subquery->groupBy('sub.target_node');
    return $subquery;
  }

  /**
   * Compute median score from latest assessments.
   *
   * @return float|null
   *   The median score, or NULL if no assessments exist.
   */
  protected function computeMedianScore(): ?float {
    $latest = $this->latestAssessmentSubquery();

    // Get total count first.
    $countQuery = $this->database->select(self::TABLE, 'a');
    $countQuery->join($latest, 'latest', 'a.id = latest.max_id');
    $total = (int) $countQuery->countQuery()->execute()->fetchField();

    if ($total === 0) {
      return NULL;
    }

    $offset = (int) floor(($total - 1) / 2);

    $latest2 = $this->latestAssessmentSubquery();
    $query = $this->database->select(self::TABLE, 'a');
    $query->join($latest2, 'latest', 'a.id = latest.max_id');
    $query->addField('a', 'score');
    $query->orderBy('a.score', 'ASC');

    if ($total % 2 === 1) {
      // Odd count — single middle value.
      $query->range($offset, 1);
      $val = $query->execute()->fetchField();
      return $val !== FALSE ? (float) $val : NULL;
    }

    // Even count — average of two middle values.
    $query->range($offset, 2);
    $values = $query->execute()->fetchCol();
    if (count($values) === 2) {
      return round(((float) $values[0] + (float) $values[1]) / 2, 1);
    }

    return NULL;
  }

  /**
   * Cache wrapper that stores results with appropriate tags.
   *
   * @param string $cid
   *   Cache ID.
   * @param callable $compute
   *   Callback to compute the value if not cached.
   * @param int $maxAge
   *   Cache max-age in seconds.
   *
   * @return mixed
   *   The cached or freshly computed value.
   */
  protected function getCachedOrCompute(string $cid, callable $compute, int $maxAge = self::DEFAULT_CACHE_MAX_AGE): mixed {
    $cached = $this->cache->get($cid);
    if ($cached) {
      return $cached->data;
    }

    try {
      $data = $compute();
      $this->cache->set($cid, $data, time() + $maxAge, [
        'ai_content_assessment_list',
        'ai_site_audit:summary',
      ]);
      return $data;
    }
    catch (\Exception $e) {
      $this->logger->error('Aggregation query failed for @cid: @message', [
        '@cid' => $cid,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
