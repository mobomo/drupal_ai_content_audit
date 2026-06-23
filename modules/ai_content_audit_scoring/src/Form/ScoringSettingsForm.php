<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Form;

use Drupal\ai_content_audit\Plugin\Manager\AuditCheckManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for AIRO Scoring.
 */
final class ScoringSettingsForm extends ConfigFormBase {

  /**
   * The parent config object managed by this submodule.
   */
  private const CONFIG_NAME = 'ai_content_audit.settings';

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AuditCheckManager $auditCheckManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('ai_content_audit.audit_check_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_content_audit_scoring_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    $form['assessment'] = [
      '#type' => 'details',
      '#title' => $this->t('Assessment automation'),
      '#open' => TRUE,
    ];

    $form['assessment']['assess_on_save'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Run AI assessment automatically when a node is saved'),
      '#default_value' => (bool) ($config->get('enable_on_save') ?? FALSE),
      '#description' => $this->t('Assessments are enqueued and processed by cron.'),
    ];

    $form['assessment']['node_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Node types to assess on save'),
      '#options' => $this->getNodeTypeOptions(),
      '#default_value' => $config->get('node_types') ?? [],
      '#description' => $this->t('Leave all unchecked to assess every published node type.'),
      '#states' => [
        'visible' => [
          ':input[name="assess_on_save"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['assessment']['render_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Content extraction mode'),
      '#options' => [
        'text' => $this->t('Plain text'),
        'html' => $this->t('Rendered HTML'),
      ],
      '#default_value' => $config->get('render_mode') ?? 'text',
      '#description' => $this->t('How node content is extracted for saved assessments.'),
    ];

    $form['history'] = [
      '#type' => 'details',
      '#title' => $this->t('Assessment history'),
      '#open' => TRUE,
    ];

    $form['history']['enable_history'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Keep assessment history'),
      '#default_value' => (bool) ($config->get('enable_history') ?? TRUE),
      '#description' => $this->t('When disabled, only the latest assessment per node is kept.'),
    ];

    $form['history']['max_assessments_per_node'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum assessments per node'),
      '#description' => $this->t('Set to 0 to keep all assessment records.'),
      '#default_value' => $config->get('max_assessments_per_node') ?? 10,
      '#min' => 0,
    ];

    $check_options = $this->getAuditCheckOptions();
    if ($check_options !== []) {
      $form['technical_audit'] = [
        '#type' => 'details',
        '#title' => $this->t('Technical audit checks'),
        '#open' => FALSE,
      ];

      $form['technical_audit']['disabled_checks'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Disabled checks'),
        '#options' => $check_options,
        '#default_value' => $config->get('disabled_checks') ?? [],
        '#description' => $this->t('Selected checks are excluded from scoring technical audit output.'),
      ];
    }

    $form['prompts'] = [
      '#type' => 'details',
      '#title' => $this->t('Assessment prompts'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['prompts']['assessment_system_prompt'] = [
      '#type' => 'ai_prompt',
      '#title' => $this->t('Assessment system prompt'),
      '#prompt_types' => ['content_audit_assessment_system'],
      '#config_target' => self::CONFIG_NAME . ':prompts.assessment_system_prompt',
      '#parents' => ['prompts', 'assessment_system_prompt'],
      '#description' => $this->t('Controls the system instructions for saved AI readiness assessments.'),
    ];

    $form['prompts']['assessment_user_prompt'] = [
      '#type' => 'ai_prompt',
      '#title' => $this->t('Assessment user prompt'),
      '#prompt_types' => ['content_audit_assessment_user'],
      '#config_target' => self::CONFIG_NAME . ':prompts.assessment_user_prompt',
      '#parents' => ['prompts', 'assessment_user_prompt'],
      '#description' => $this->t('Must include the required assessment variables.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node_types = array_keys(array_filter((array) $form_state->getValue('node_types')));
    $disabled_checks = array_keys(array_filter((array) $form_state->getValue('disabled_checks')));

    $this->config(self::CONFIG_NAME)
      ->set('enable_on_save', (bool) $form_state->getValue('assess_on_save'))
      ->set('node_types', $node_types)
      ->set('render_mode', (string) $form_state->getValue('render_mode'))
      ->set('enable_history', (bool) $form_state->getValue('enable_history'))
      ->set('max_assessments_per_node', (int) $form_state->getValue('max_assessments_per_node'))
      ->set('disabled_checks', $disabled_checks)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      self::CONFIG_NAME,
    ];
  }

  /**
   * Returns node type options keyed by machine name.
   *
   * @return array<string, string>
   *   Node type labels keyed by node type ID.
   */
  private function getNodeTypeOptions(): array {
    $options = [];
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    foreach ($types as $id => $type) {
      $options[$id] = $type->label();
    }
    return $options;
  }

  /**
   * Returns audit check options keyed by plugin ID.
   *
   * @return array<string, string>
   *   Audit check labels keyed by plugin ID.
   */
  private function getAuditCheckOptions(): array {
    $options = [];
    foreach ($this->auditCheckManager->getDefinitions() as $id => $definition) {
      $category = (string) ($definition['category'] ?? $this->t('Other'));
      $label = (string) ($definition['label'] ?? $id);
      $options[$id] = $category . ': ' . $label;
    }
    asort($options);
    return $options;
  }

}
