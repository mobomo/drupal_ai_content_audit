<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_content_audit\Service\ProviderModelChoices;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the AI Content Audit module.
 *
 * Route: /admin/config/ai/content-audit
 * Permission: administer ai content audit.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The config object name managed by this form.
   */
  const CONFIG_NAME = 'ai_content_audit.settings';

  /**
   * The AI provider plugin manager.
   */
  protected ?AiProviderPluginManager $aiProviderManager = NULL;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The provider model choices helper service.
   */
  protected ?ProviderModelChoices $providerModelChoices = NULL;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    AiProviderPluginManager $ai_provider_manager,
    EntityTypeManagerInterface $entity_type_manager,
    ProviderModelChoices $provider_model_choices,
    AccountInterface $current_user,
  ) {
    parent::__construct($config_factory, $typedConfigManager);

    $this->aiProviderManager = $ai_provider_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->providerModelChoices = $provider_model_choices;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai.provider'),
      $container->get('entity_type.manager'),
      $container->get('ai_content_audit.provider_model_choices'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_content_audit_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    // Show provider status at the top of the form.
    $providers_url = Url::fromRoute('ai.admin_providers')->toString();

    if ($this->aiProviderManager->hasProvidersForOperationType('chat')) {
      $default = $this->aiProviderManager->getDefaultProviderForOperationType('chat');
      $form['provider_status'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--status">' . $this->t('✓ A chat provider is configured (global default: <strong>@provider</strong> / <strong>@model</strong>). To manage API keys and providers, visit <a href=":url">AI Providers configuration</a>.', [
          '@provider' => $default['provider_id'] ?? '—',
          '@model' => $default['model_id'] ?? '—',
          ':url' => $providers_url,
        ]) . '</div>',
        '#weight' => -100,
        '#access' => $this->currentUser->hasPermission('administer ai content audit'),
      ];
    }
    else {
      $form['provider_status'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t('⚠ No AI chat provider is configured. Install a provider module (e.g. <em>AI Provider: OpenAI</em> or <em>AI Provider: Anthropic</em>) and <a href=":url">configure it here</a> before running assessments.', [
          ':url' => $providers_url,
        ]) . '</div>',
        '#weight' => -100,
        '#access' => $this->currentUser->hasPermission('administer ai content audit'),
      ];
    }

    $form['provider_model_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Default AI provider and model'),
      '#access' => $this->currentUser->hasPermission('administer ai content audit'),
    ];

    // Build Drupal AI simple provider/model options for chat.
    $provider_options = $this->providerModelChoices->getGroupedSelectOptions('chat');

    // Current saved value uses Drupal AI's simple provider/model option.
    $saved_key = (string) ($config->get('default_provider_model') ?? '');

    if (!empty($provider_options)) {
      // Prepend a "use global default" option.
      $select_options = ['' => $this->t('— Use global AI default —')] + $provider_options;

      $form['provider_model_fieldset']['default_provider_model'] = [
        '#type' => 'select',
        '#title' => $this->t('Provider / model'),
        '#options' => $select_options,
        '#default_value' => $saved_key,
        '#parents' => ['default_provider_model'],
        '#description' => $this->t('Select the AI provider and model to use for content audits. Choose "Use global AI default" to defer to the site-wide setting configured at <a href=":url">AI Providers</a>.', [
          ':url' => $providers_url,
        ]),
      ];
    }
    else {
      $form['provider_model_fieldset']['default_provider_model'] = [
        '#type' => 'markup',
        '#markup' => '<p class="messages messages--warning">' . $this->t('No configured AI chat providers found. Please <a href=":url">configure at least one provider</a> first.', [
          ':url' => $providers_url,
        ]) . '</p>',
      ];
    }

    $form['assess_on_save'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Run AI assessment automatically when a node is saved'),
      '#default_value' => (bool) ($config->get('enable_on_save') ?? FALSE),
      '#description' => $this->t('Assessments will be enqueued and processed by cron.'),
      '#access' => $this->currentUser->hasPermission('administer ai content audit'),
    ];

    $form['node_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Node types to assess on save'),
      '#options' => $this->getNodeTypeOptions(),
      '#default_value' => $config->get('node_types') ?? [],
      '#description' => $this->t('Select which node types trigger automatic assessment. Leave all unchecked to assess every published node type.'),
      '#states' => [
        'visible' => [
          ':input[name="assess_on_save"]' => ['checked' => TRUE],
        ],
      ],
      '#access' => $this->currentUser->hasPermission('administer ai content audit'),
    ];

    $form['max_chars_per_request'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum characters per AI request'),
      '#default_value' => (int) ($config->get('max_chars_per_request') ?? 8000),
      '#min' => 500,
      '#max' => 32000,
      '#step' => 100,
      '#description' => $this->t('Content is truncated to this length before sending to the AI provider to avoid token overruns. Default: 8000.'),
      '#access' => $this->currentUser->hasPermission('administer ai content audit'),
    ];

    $form['enable_history'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Keep assessment history'),
      '#default_value' => (bool) ($config->get('enable_history') ?? TRUE),
      '#description' => $this->t('When enabled, all past assessments are stored. When disabled, only the latest assessment per node is kept.'),
      '#access' => $this->currentUser->hasPermission('administer ai content audit'),
    ];

    $form['max_assessments_per_node'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum assessments per node'),
      '#description' => $this->t('Number of historical assessments to retain per node. Set to 0 to keep all. Older records are pruned during cron.'),
      '#default_value' => $config->get('max_assessments_per_node') ?? 10,
      '#min' => 0,
      '#access' => $this->currentUser->hasPermission('administer ai content audit'),
    ];

    $form['render_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Content extraction mode'),
      '#options' => [
        'text' => $this->t('Plain text (default)'),
        'html' => $this->t('Rendered HTML'),
      ],
      '#default_value' => $config->get('render_mode') ?? 'text',
      '#description' => $this->t('How node content is extracted for AI analysis.'),
      '#access' => $this->currentUser->hasPermission('administer ai content audit'),
    ];

    $form['prompts'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Content Audit prompts'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#access' => $this->currentUser->hasPermission('manage content audit prompts') || $this->currentUser->hasPermission('administer ai content audit'),
    ];

    $form['prompts']['preview_system_prompt'] = [
      '#type' => 'ai_prompt',
      '#title' => $this->t('Preview system prompt'),
      '#prompt_types' => ['content_audit_preview_system'],
      '#config_target' => self::CONFIG_NAME . ':prompts.preview_system_prompt',
      '#parents' => ['prompts', 'preview_system_prompt'],
      '#description' => $this->t('Controls the AIRO Preview chat system behavior.'),
    ];

    $form['prompts']['preview_user_prompt'] = [
      '#type' => 'ai_prompt',
      '#title' => $this->t('Preview user prompt'),
      '#prompt_types' => ['content_audit_preview_user'],
      '#config_target' => self::CONFIG_NAME . ':prompts.preview_user_prompt',
      '#parents' => ['prompts', 'preview_user_prompt'],
      '#description' => $this->t('Must include the page content and visitor question variables.'),
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
      '#description' => $this->t('Must include the required assessment variables defined by its prompt type.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Returns options array of all node types keyed by machine name.
   */
  private function getNodeTypeOptions(): array {
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $options = [];
    foreach ($types as $id => $type) {
      $options[$id] = $type->label();
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->currentUser->hasPermission('administer ai content audit')) {
      $node_types = array_keys(array_filter((array) $form_state->getValue('node_types')));

      $this->config(self::CONFIG_NAME)
        ->set('enable_on_save', (bool) $form_state->getValue('assess_on_save'))
        ->set('node_types', $node_types)
        ->set('max_chars_per_request', (int) $form_state->getValue('max_chars_per_request'))
        ->set('enable_history', (bool) $form_state->getValue('enable_history'))
        ->set('max_assessments_per_node', (int) $form_state->getValue('max_assessments_per_node'))
        ->set('render_mode', $form_state->getValue('render_mode'))
        ->set('default_provider_model', (string) ($form_state->getValue('default_provider_model') ?? ''))
        ->save();
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Must return the exact config name(s) written in submitForm().
   */
  protected function getEditableConfigNames(): array {
    return [
      self::CONFIG_NAME,
    ];
  }

}
