<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_content_audit\Service\ProviderModelChoices;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
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
   * {@inheritdoc}
   */
  public function __construct($config_factory, TypedConfigManagerInterface $typedConfigManager, AiProviderPluginManager $ai_provider_manager, EntityTypeManagerInterface $entity_type_manager, ProviderModelChoices $provider_model_choices) {
    parent::__construct($config_factory, $typedConfigManager);

    $this->aiProviderManager = $ai_provider_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->providerModelChoices = $provider_model_choices;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('config.factory'), $container->get('config.typed'), $container->get('ai.provider'), $container->get('entity_type.manager'), $container->get('ai_content_audit.provider_model_choices'),);
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
      ];
    }
    else {
      $form['provider_status'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t('⚠ No AI chat provider is configured. Install a provider module (e.g. <em>AI Provider: OpenAI</em> or <em>AI Provider: Anthropic</em>) and <a href=":url">configure it here</a> before running assessments.', [
          ':url' => $providers_url,
        ]) . '</div>',
        '#weight' => -100,
      ];
    }

    $form['provider_model_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Default AI provider and model'),
    ];

    // Build the grouped select options from all enabled chat providers.
    $provider_options = $this->providerModelChoices->getGroupedSelectOptions('chat');

    // Current saved value — reconstruct the composite key.
    $saved_provider = $config->get('default_provider') ?? '';
    $saved_model = $config->get('default_model') ?? '';
    $saved_key = ($saved_provider !== '' && $saved_model !== '') ? $saved_provider . '__' . $saved_model : '';

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
    ];

    $form['max_chars_per_request'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum characters per AI request'),
      '#default_value' => (int) ($config->get('max_chars_per_request') ?? 8000),
      '#min' => 500,
      '#max' => 32000,
      '#step' => 100,
      '#description' => $this->t('Content is truncated to this length before sending to the AI provider to avoid token overruns. Default: 8000.'),
    ];

    $form['enable_history'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Keep assessment history'),
      '#default_value' => (bool) ($config->get('enable_history') ?? TRUE),
      '#description' => $this->t('When enabled, all past assessments are stored. When disabled, only the latest assessment per node is kept.'),
    ];

    $form['max_assessments_per_node'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum assessments per node'),
      '#description' => $this->t('Number of historical assessments to retain per node. Set to 0 to keep all. Older records are pruned during cron.'),
      '#default_value' => $config->get('max_assessments_per_node') ?? 10,
      '#min' => 0,
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
    ];

    $form['prompts'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Content Audit prompts.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['prompts']['enable_custom_system_prompt'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable custom system prompt'),
      '#description' => $this->t('Enable this to add a custom system prompt instead of the default/recommended.'),
      '#default_value' => $config->get('enable_custom_system_prompt') ?? FALSE,
    ];

    $form['prompts']['system_prompt'] = [
      '#type' => 'ai_prompt',
      '#title' => $this->t('Custom System Prompt Selection'),
      '#prompt_types' => ['content_audit_system'],
      '#config_target' => self::CONFIG_NAME . ':prompts.system_prompt',
      '#parents' => ['prompts', 'system_prompt'],
      '#states' => [
        'visible' => [
          ':input[name="prompts[enable_custom_system_prompt]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['prompts']['enable_custom_user_prompt'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable custom user prompt'),
      '#description' => $this->t('Enable this to add a custom user prompt instead of the default/recommended.'),
      '#default_value' => $config->get('enable_custom_user_prompt') ?? FALSE,
    ];

    $form['prompts']['user_prompt'] = [
      '#type' => 'ai_prompt',
      '#title' => $this->t('Custom User Prompt Selection'),
      '#prompt_types' => ['content_audit_user'],
      '#config_target' => self::CONFIG_NAME . ':prompts.user_prompt',
      '#parents' => ['prompts', 'user_prompt'],
      '#states' => [
        'visible' => [
          ':input[name="prompts[enable_custom_user_prompt]"]' => ['checked' => TRUE],
        ],
      ],
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
    // array_filter removes unchecked checkboxes (Drupal uses 0 when unchecked).
    $node_types = array_keys(array_filter($form_state->getValue('node_types')));

    // Split the composite 'provider__model' key into separate config values.
    $provider_model_key = (string) ($form_state->getValue('default_provider_model') ?? '');
    [
      $default_provider,
      $default_model,
    ] = $this->providerModelChoices->parseKey($provider_model_key);

    $this->config(self::CONFIG_NAME)
      ->set('enable_on_save', (bool) $form_state->getValue('assess_on_save'))
      ->set('node_types', $node_types)
      ->set('max_chars_per_request', (int) $form_state->getValue('max_chars_per_request'))
      ->set('enable_history', (bool) $form_state->getValue('enable_history'))
      ->set('max_assessments_per_node', (int) $form_state->getValue('max_assessments_per_node'))
      ->set('render_mode', $form_state->getValue('render_mode'))
      ->set('default_provider', $default_provider)
      ->set('default_model', $default_model)
      ->set('enable_custom_system_prompt', (bool) $form_state->getValue([
        'prompts',
        'enable_custom_system_prompt',
      ]))
      ->set('enable_custom_user_prompt', (bool) $form_state->getValue([
        'prompts',
        'enable_custom_user_prompt',
      ]))
      ->save();

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
