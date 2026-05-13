<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Entity;

use Drupal\ai_content_audit\AiContentAssessmentAccessControlHandler;
use Drupal\ai_content_audit\AiContentAssessmentListBuilder;
use Drupal\ai_content_audit\AiContentAssessmentViewsData;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the AiContentAssessment content entity.
 *
 * Stores node assessment results: score, structured payload, raw response text,
 * and provider metadata.
 */
#[ContentEntityType(
  id: 'ai_content_assessment',
  label: new TranslatableMarkup('AI Content Assessment'),
  label_collection: new TranslatableMarkup('AI Content Assessments'),
  label_singular: new TranslatableMarkup('AI content assessment'),
  label_plural: new TranslatableMarkup('AI content assessments'),
  entity_keys: [
    'id'    => 'id',
    'uuid'  => 'uuid',
    'label' => 'label',
  ],
   handlers: [
     'access'       => AiContentAssessmentAccessControlHandler::class,
     'list_builder' => AiContentAssessmentListBuilder::class,
     'views_data'   => AiContentAssessmentViewsData::class,
     'form' => [
       'delete' => 'Drupal\ai_content_audit\Form\AiContentAssessmentDeleteForm',
     ],
   ],
   links: [
     'canonical'   => '/admin/content/ai-assessments/{ai_content_assessment}',
     'delete-form' => '/admin/content/ai-assessments/{ai_content_assessment}/delete',
     'collection'  => '/admin/content/ai-assessments',
   ],
  admin_permission: 'administer ai content audit',
  base_table: 'ai_content_assessment',
  label_count: [
    'singular' => '@count assessment',
    'plural'   => '@count assessments',
  ],
)]
class AiContentAssessment extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->isNew() && $this->get('run_by')->isEmpty()) {
      $this->set('run_by', \Drupal::currentUser()->id());
    }
  }

  /**
   * Returns the assessed node entity.
   */
  public function getTargetNode(): ?\Drupal\node\NodeInterface {
    return $this->get('target_node')->entity;
  }

  /**
   * Returns the AI readiness score (0–100).
   */
  public function getScore(): ?int {
    $value = $this->get('score')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * Returns the parsed JSON result as a PHP array.
   */
  public function getParsedResult(): array {
    $json = $this->get('result_json')->value;
    return $json ? (json_decode($json, TRUE) ?? []) : [];
  }

  /**
   * Returns the sub-scores array decoded from JSON, or NULL if not set.
   *
   * @return array|null
   *   Array of dimension score objects, or NULL.
   */
  public function getSubScores(): ?array {
    $json = $this->get('sub_scores')->value;
    return $json ? (json_decode($json, TRUE) ?? NULL) : NULL;
  }

  /**
   * Sets the sub-scores from a PHP array (stored as JSON).
   *
   * @param array|null $sub_scores
   *   Array of dimension score objects, or NULL to clear.
   *
   * @return static
   */
  public function setSubScores(?array $sub_scores): static {
    $this->set('sub_scores', $sub_scores !== NULL ? json_encode($sub_scores) : NULL);
    return $this;
  }

  /**
   * Returns the checkpoints array decoded from JSON, or NULL if not set.
   *
   * @return array|null
   *   Array of checkpoint objects, or NULL.
   */
  public function getCheckpoints(): ?array {
    $json = $this->get('checkpoints')->value;
    return $json ? (json_decode($json, TRUE) ?? NULL) : NULL;
  }

  /**
   * Sets the checkpoints from a PHP array (stored as JSON).
   *
   * @param array|null $checkpoints
   *   Array of checkpoint objects, or NULL to clear.
   *
   * @return static
   */
  public function setCheckpoints(?array $checkpoints): static {
    $this->set('checkpoints', $checkpoints !== NULL ? json_encode($checkpoints) : NULL);
    return $this;
  }

  /**
   * Returns the action items array decoded from JSON, or NULL if not set.
   *
   * @return array|null
   *   Array of action item objects, or NULL.
   */
  public function getActionItems(): ?array {
    $json = $this->get('action_items')->value;
    return $json ? (json_decode($json, TRUE) ?? NULL) : NULL;
  }

  /**
   * Sets action items from a PHP array (stored as JSON).
   *
   * @param array|null $action_items
   *   Array of action item objects, or NULL to clear.
   *
   * @return static
   */
  public function setActionItems(?array $action_items): static {
    $this->set('action_items', $action_items !== NULL ? json_encode($action_items) : NULL);
    return $this;
  }

  /**
   * Gets the action items completion status.
   */
  public function getActionItemsStatus(): ?array {
    $value = $this->get('action_items_status')->value;
    return $value ? json_decode($value, TRUE) : NULL;
  }

  /**
   * Sets the action items completion status.
   */
  public function setActionItemsStatus(?array $status): static {
    $this->set('action_items_status', $status !== NULL ? json_encode($status) : NULL);
    return $this;
  }

  /**
   * Returns the score trend delta (point change from previous assessment).
   *
   * @return int|null
   *   Integer delta, or NULL if not yet computed.
   */
  public function getScoreTrendDelta(): ?int {
    $value = $this->get('score_trend_delta')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * Sets the score trend delta.
   *
   * @param int|null $delta
   *   Point change from the previous assessment, or NULL to clear.
   *
   * @return static
   */
  public function setScoreTrendDelta(?int $delta): static {
    $this->set('score_trend_delta', $delta);
    return $this;
  }

  /**
   * Default value callback for the label field.
   *
   * Generates a human-readable label from the current timestamp so that
   * $entity->label() never returns NULL, preventing blank breadcrumbs and
   * empty admin UI titles.
   *
   * @return string
   *   A label string such as "Assessment 2024-01-15 09:30:00".
   */
  public static function getDefaultLabel(): string {
    return 'Assessment ' . date('Y-m-d H:i:s');
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    // Provides 'id' (serial) and 'uuid' automatically.
    $fields = parent::baseFieldDefinitions($entity_type);

    // M-6: Human-readable label so $entity->label() never returns NULL.
    // Used by breadcrumbs, admin UI titles, and Views label fields.
    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('A generated label for this assessment.'))
      ->setDefaultValueCallback(static::class . '::getDefaultLabel')
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type'  => 'string',
        'weight' => -20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // The user who triggered the assessment (nullable plain entity_reference).
    $fields['run_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Run by'))
      ->setDescription(new TranslatableMarkup('The user who triggered the assessment. NULL for queue/cron runs.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRequired(FALSE)
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label'  => 'inline',
        'type'   => 'author',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // The assessed node.
    $fields['target_node'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Assessed node'))
      ->setDescription(new TranslatableMarkup('The node that was assessed.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('view', [
        'label'  => 'above',
        'type'   => 'entity_reference_label',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // AI provider machine name.
    $fields['provider_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('AI provider'))
      ->setDescription(new TranslatableMarkup('Machine name of the AI provider (e.g. "openai").'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('view', [
        'label'  => 'inline',
        'type'   => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // AI model identifier.
    $fields['model_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('AI model'))
      ->setDescription(new TranslatableMarkup('Model identifier used for the assessment (e.g. "gpt-4o").'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('view', [
        'label'  => 'inline',
        'type'   => 'string',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // AI readiness score 0-100.
    $fields['score'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('AI Readiness Score'))
      ->setDescription(new TranslatableMarkup('Overall AI readiness score from 0 (poor) to 100 (excellent).'))
      ->setRequired(FALSE)
      ->setSetting('min', 0)
      ->setSetting('max', 100)
      ->setDefaultValue(0)
      ->addPropertyConstraints('value', [
        'Range' => ['min' => 0, 'max' => 100],
      ])
      ->setDisplayOptions('view', [
        'label'  => 'inline',
        'type'   => 'number_integer',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Parsed JSON result from LLM. Use string_long (no text format overhead).
    $fields['result_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Parsed JSON result'))
      ->setDescription(new TranslatableMarkup('The structured JSON assessment result from the LLM.'))
      ->setRequired(FALSE)
      ->addPropertyConstraints('value', ['ValidJson' => []]);

    // Raw unprocessed LLM text output.
    $fields['raw_output'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Raw LLM output'))
      ->setDescription(new TranslatableMarkup('The unprocessed text response from the LLM before JSON parsing.'))
      ->setRequired(FALSE);

    // Assessment creation timestamp (auto-set).
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('Timestamp when the assessment was created.'))
      ->setDisplayOptions('view', [
        'label'  => 'inline',
        'type'   => 'timestamp',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // JSON array of dimension score breakdowns (v2 schema).
    $fields['sub_scores'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Sub-scores'))
      ->setDescription(new TranslatableMarkup('JSON array of dimension score breakdowns.'))
      ->setRequired(FALSE)
      ->setDefaultValue(NULL)
      ->addPropertyConstraints('value', ['ValidJson' => []]);

    // JSON array of checkpoint pass/fail/warning items (v2 schema).
    $fields['checkpoints'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Checkpoints'))
      ->setDescription(new TranslatableMarkup('JSON array of checkpoint audit items.'))
      ->setRequired(FALSE)
      ->setDefaultValue(NULL)
      ->addPropertyConstraints('value', ['ValidJson' => []]);

    // JSON array of prioritised action items (v2 schema).
    $fields['action_items'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Action items'))
      ->setDescription(new TranslatableMarkup('JSON array of recommended action items.'))
      ->setRequired(FALSE)
      ->setDefaultValue(NULL)
      ->addPropertyConstraints('value', ['ValidJson' => []]);

    // JSON object tracking per-item completion state.
    $fields['action_items_status'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Action items status'))
      ->setDescription(t('JSON object tracking per-item completion state.'))
      ->setRequired(FALSE)
      ->setDefaultValue(NULL)
      ->addPropertyConstraints('value', ['ValidJson' => []]);

    // Point change from the previous assessment for the same node.
    $fields['score_trend_delta'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Score trend delta'))
      ->setDescription(new TranslatableMarkup('Point change from previous assessment.'))
      ->setRequired(FALSE)
      ->setDefaultValue(NULL);

    return $fields;
  }

}
