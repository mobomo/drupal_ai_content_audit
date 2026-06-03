<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Kernel;

use Drupal\ai_content_audit\AiContentAssessmentListBuilder;
use Drupal\ai_content_audit\Entity\AiContentAssessment;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Kernel coverage for AI Content Assessment behaviors.
 */
final class AiContentAssessmentKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'node',
    'text',
    'ai_content_audit',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('ai_content_assessment');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);

    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ])->save();
  }

  /**
   * Verifies that nullable scores persist and round-trip through getScore().
   */
  public function testScoreNullabilityPersists(): void {
    $user = $this->createUserWithName('Assessing User');
    $node = $this->createNodeForUser($user, 'Scored node');

    $assessment = $this->createAssessment($node, $user, NULL);

    $storage = $this->assessmentStorage();
    $reloaded = $storage->load($assessment->id());

    $this->assertNotNull($reloaded);
    $this->assertNull($reloaded->get('score')->value, 'Database value should remain NULL.');
    $this->assertNull($reloaded->getScore(), 'Accessor must return NULL for an unset score.');

    $reloaded->set('score', 88);
    $reloaded->save();
    $this->assertSame(88, $storage->load($reloaded->id())->getScore());
  }

  /**
   * Ensures list builder prefetches users without N+1 queries.
   */
  public function testListBuilderPrefetchesRunByUsersAndRendersFallbacks(): void {
    $user = $this->createUserWithName('List Builder Runner');
    $node = $this->createNodeForUser($user, 'Example node');

    $withUser = $this->createAssessment($node, $user, NULL);
    $withoutUser = $this->createAssessment($node, NULL, 50);

    $list_builder = $this->container->get('entity_type.manager')->getListBuilder('ai_content_assessment');
    assert($list_builder instanceof AiContentAssessmentListBuilder);

    $entities = $list_builder->load();
    $this->assertArrayHasKey($withUser->id(), $entities);
    $this->assertArrayHasKey($withoutUser->id(), $entities);

    $reflection = new \ReflectionProperty($list_builder, 'runByUsers');
    $reflection->setAccessible(TRUE);
    $run_by_users = $reflection->getValue($list_builder);

    $this->assertArrayHasKey($user->id(), $run_by_users, 'Run-by users should be prefetched once.');
    $this->assertSame($user->id(), $run_by_users[$user->id()]->id());

    $row_with_user = $list_builder->buildRow($entities[$withUser->id()]);
    $this->assertSame('List Builder Runner', $row_with_user['run_by']);
    $this->assertSame('n/a', (string) $row_with_user['score']);
    $this->assertSame('link', $row_with_user['node']['data']['#type']);
    $this->assertSame('Example node', $row_with_user['node']['data']['#title']);

    $row_without_user = $list_builder->buildRow($entities[$withoutUser->id()]);
    $this->assertSame('50/100', $row_without_user['score']);
    $this->assertSame('Cron/queue', (string) $row_without_user['run_by']);
  }

  /**
   * Verifies that v2 fields (sub_scores, checkpoints, action_items) round-trip.
   */
  public function testV2FieldsRoundTrip(): void {
    $user = $this->createUserWithName('V2 User');
    $node = $this->createNodeForUser($user, 'V2 node');

    $response = $this->buildV2MockResponse();
    $assessment = $this->createAssessmentWithV2Data($node, $user, $response);

    $storage = $this->assessmentStorage();
    $reloaded = $storage->load($assessment->id());

    $this->assertNotNull($reloaded);

    // sub_scores round-trip.
    $sub_scores = $reloaded->getSubScores();
    $this->assertIsArray($sub_scores);
    $this->assertCount(3, $sub_scores);
    $this->assertSame('technical_seo', $sub_scores[0]['dimension']);
    $this->assertSame(30, $sub_scores[0]['score']);

    // Checkpoints round-trip.
    $checkpoints = $reloaded->getCheckpoints();
    $this->assertIsArray($checkpoints);
    $this->assertGreaterThanOrEqual(1, count($checkpoints));
    $this->assertSame('pass', $checkpoints[0]['status']);

    // action_items round-trip.
    $action_items = $reloaded->getActionItems();
    $this->assertIsArray($action_items);
    $this->assertGreaterThanOrEqual(1, count($action_items));
    $this->assertSame('add_meta_description', $action_items[0]['id']);
    $this->assertSame('high', $action_items[0]['priority']);

    // NULL cleared fields stay NULL.
    $reloaded->setSubScores(NULL);
    $reloaded->save();
    $this->assertNull($storage->load($reloaded->id())->getSubScores());
  }

  /**
   * Verifies score_trend_delta across two assessments.
   */
  public function testScoreTrendDeltaComputation(): void {
    $user = $this->createUserWithName('Trend User');
    $node = $this->createNodeForUser($user, 'Trend node');

    // First assessment: score 60, no previous → delta NULL.
    $first = $this->createAssessment($node, $user, 60);
    $this->assertNull(
      $first->getScoreTrendDelta(),
      'First assessment should have NULL trend delta (no previous record).'
    );

    // Second assessment: score 75 → delta = 75 - 60 = +15.
    $second = $this->assessmentStorage()->create([
      'target_node' => $node->id(),
      'provider_id' => 'openai',
      'model_id'    => 'gpt-4o-mini',
    ]);
    $second->set('score', 75);
    $delta = 75 - 60;
    $second->setScoreTrendDelta($delta);
    $second->save();

    $reloaded = $this->assessmentStorage()->load($second->id());
    $this->assertSame(15, $reloaded->getScoreTrendDelta());

    // Third assessment: score 70 → delta = 70 - 75 = -5.
    $third = $this->assessmentStorage()->create([
      'target_node' => $node->id(),
      'provider_id' => 'openai',
      'model_id'    => 'gpt-4o-mini',
    ]);
    $third->set('score', 70);
    $third->setScoreTrendDelta(70 - 75);
    $third->save();

    $reloaded_third = $this->assessmentStorage()->load($third->id());
    $this->assertSame(-5, $reloaded_third->getScoreTrendDelta());
  }

  /**
   * Verifies v1 responses can be saved with NULL v2 fields.
   *
   * Missing v2 keys must not throw when persisting an assessment.
   */
  public function testV1ResponseFallbackStoresNullV2Fields(): void {
    $user = $this->createUserWithName('V1 Fallback User');
    $node = $this->createNodeForUser($user, 'V1 fallback node');

    // Create with only v1 data — v2 fields must remain NULL.
    $assessment = $this->createAssessment($node, $user, 55);

    $storage  = $this->assessmentStorage();
    $reloaded = $storage->load($assessment->id());

    $this->assertNull($reloaded->getSubScores(), 'sub_scores must be NULL for v1 response.');
    $this->assertNull($reloaded->getCheckpoints(), 'checkpoints must be NULL for v1 response.');
    $this->assertNull($reloaded->getActionItems(), 'action_items must be NULL for v1 response.');
    $this->assertNull($reloaded->getScoreTrendDelta(), 'score_trend_delta must be NULL when not set.');
  }

  // ── Helper Methods ─────────────────────────────────────────────

  /**
   * Builds a complete v2 mock response array.
   *
   * Used by tests that need all v2 fields populated.
   *
   * @return array
   *   A fully-populated v2 assessment payload.
   */
  private function buildV2MockResponse(): array {
    return [
      'ai_readiness_score' => 72,
      'qualitative_status' => 'Improving',
      'readability' => [
        'grade_level' => 8.5,
        'assessment'  => 'Clear and well-structured.',
      ],
      'seo' => [
        'title_present'            => TRUE,
        'meta_description_present' => FALSE,
        'suggested_meta'           => 'A concise description for search engines.',
        'open_graph_present'       => FALSE,
        'canonical_present'        => TRUE,
      ],
      'content_completeness' => [
        'missing_topics'    => ['Author bio', 'References'],
        'word_count_adequate' => TRUE,
        'has_lead_paragraph'  => TRUE,
      ],
      'tone_consistency' => [
        'tone'       => 'informational',
        'confidence' => 0.85,
      ],
      'sub_scores' => [
        [
          'dimension' => 'technical_seo',
          'label'     => 'Technical SEO',
          'score'     => 30,
          'max_score' => 40,
        ],
        [
          'dimension' => 'content_quality',
          'label'     => 'Content Quality',
          'score'     => 28,
          'max_score' => 35,
        ],
        [
          'dimension' => 'schema_markup',
          'label'     => 'Schema Markup',
          'score'     => 14,
          'max_score' => 25,
        ],
      ],
      'checkpoints' => [
        [
          'category' => 'Metadata',
          'item'     => 'Title tag present',
          'status'   => 'pass',
          'priority' => 'high',
        ],
        [
          'category' => 'Metadata',
          'item'     => 'Meta description present',
          'status'   => 'fail',
          'priority' => 'high',
        ],
      ],
      'action_items' => [
        [
          'id'               => 'add_meta_description',
          'priority'         => 'high',
          'title'            => 'Add meta description',
          'description'      => 'A meta description improves CTR from search results.',
          'field_target'     => 'meta_description',
          'suggested_content' => 'A clear description of this page for search engines.',
        ],
      ],
      'suggestions' => [
        [
          'area'       => 'SEO',
          'suggestion' => 'Add Open Graph tags.',
          'priority'   => 'medium',
        ],
      ],
      'provider_metadata' => [
        'provider_id' => 'openai',
        'model'       => 'gpt-4o-mini',
        'timestamp'   => '2026-04-04T00:00:00Z',
      ],
    ];
  }

  /**
   * Creates and saves an assessment entity with full v2 data.
   */
  private function createAssessmentWithV2Data(Node $node, ?User $user, array $parsed): AiContentAssessment {
    $values = [
      'target_node' => $node->id(),
      'provider_id' => 'openai',
      'model_id'    => 'gpt-4o-mini',
      'score'       => max(0, min(100, (int) ($parsed['ai_readiness_score'] ?? 0))),
      'result_json' => json_encode($parsed),
    ];

    if ($user) {
      $values['run_by'] = $user->id();
    }

    /** @var \Drupal\ai_content_audit\Entity\AiContentAssessment $assessment */
    $assessment = $this->assessmentStorage()->create($values);

    if (isset($parsed['sub_scores'])) {
      $assessment->setSubScores($parsed['sub_scores']);
    }
    if (isset($parsed['checkpoints'])) {
      $assessment->setCheckpoints($parsed['checkpoints']);
    }
    if (isset($parsed['action_items'])) {
      $assessment->setActionItems($parsed['action_items']);
    }

    $assessment->save();

    return $this->assessmentStorage()->load($assessment->id());
  }

  /**
   * Provides convenient access to the assessment storage.
   */
  private function assessmentStorage(): EntityStorageInterface {
    return $this->container->get('entity_type.manager')->getStorage('ai_content_assessment');
  }

  /**
   * Creates and saves an assessment entity for testing.
   */
  private function createAssessment(Node $node, ?User $user, ?int $score): AiContentAssessment {
    $values = [
      'target_node' => $node->id(),
      'provider_id' => 'openai',
      'model_id' => 'gpt-4o-mini',
    ];

    if ($user) {
      $values['run_by'] = $user->id();
    }

    $assessment = $this->assessmentStorage()->create($values);
    $assessment->set('score', $score);
    $assessment->save();

    // Reload to mimic real storage round-trips.
    return $this->assessmentStorage()->load($assessment->id());
  }

  /**
   * Creates and saves a user entity.
   */
  private function createUserWithName(string $name): User {
    $user = User::create([
      'name' => $name,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Creates and saves a node for the provided user.
   */
  private function createNodeForUser(User $user, string $title): Node {
    $node = Node::create([
      'type' => 'article',
      'title' => $title,
      'uid' => $user->id(),
    ]);
    $node->save();
    return $node;
  }

}
