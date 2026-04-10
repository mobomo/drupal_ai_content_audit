<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Form;

use Drupal\ai\AiProviderPluginManager;
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
 * Permission: administer ai content audit
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The config object name managed by this form.
   */
  const CONFIG_NAME = 'ai_content_audit.settings';

  public function __construct(
    $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly AiProviderPluginManager $aiProviderManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
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
   *
   * Must return the exact config name(s) written in submitForm().
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
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
        '#type'   => 'markup',
        '#markup' => '<div class="messages messages--status">'
          . $this->t('✓ A chat provider is configured (default: <strong>@provider</strong> / <strong>@model</strong>). '
              . 'To change providers, visit <a href=":url">AI Providers configuration</a>.',
            [
              '@provider' => $default['provider_id'] ?? '—',
              '@model'    => $default['model_id'] ?? '—',
              ':url'      => $providers_url,
            ]
          )
          . '</div>',
        '#weight' => -100,
      ];
    }
    else {
      $form['provider_status'] = [
        '#type'   => 'markup',
        '#markup' => '<div class="messages messages--warning">'
          . $this->t('⚠ No AI chat provider is configured. '
              . 'Install a provider module (e.g. <em>AI Provider: OpenAI</em>) and '
              . '<a href=":url">configure it here</a> before running assessments.',
            [':url' => $providers_url]
          )
          . '</div>',
        '#weight' => -100,
      ];
    }

    // ── AI provider / model note ─────────────────────────────────────────────
    $settings_url = Url::fromRoute('ai.settings_form', ['nojs' => 'nojs'])->toString();
    $form['ai_settings_note'] = [
      '#type'   => 'markup',
      '#markup' => '<p>' . $this->t(
          'AI provider and model settings are managed globally at <a href=":url">AI settings</a>.',
          [':url' => $settings_url]
        ) . '</p>',
    ];

    // ── On-save assessment toggle ────────────────────────────────────────────
    $form['assess_on_save'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Run AI assessment automatically when a node is saved'),
      '#default_value' => (bool) ($config->get('enable_on_save') ?? FALSE),
      '#description'   => $this->t('Assessments will be enqueued and processed by cron.'),
    ];

    // ── Node types (shown only when assess_on_save is checked) ───────────────
    $form['node_types'] = [
      '#type'          => 'checkboxes',
      '#title'         => $this->t('Node types to assess on save'),
      '#options'       => $this->getNodeTypeOptions(),
      '#default_value' => $config->get('node_types') ?? [],
      '#description'   => $this->t('Select which node types trigger automatic assessment. Leave all unchecked to assess every published node type.'),
      '#states'        => [
        'visible' => [
          ':input[name="assess_on_save"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // ── Max characters per request ───────────────────────────────────────────
    $form['max_chars_per_request'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Maximum characters per AI request'),
      '#default_value' => (int) ($config->get('max_chars_per_request') ?? 8000),
      '#min'           => 500,
      '#max'           => 32000,
      '#step'          => 100,
      '#description'   => $this->t('Content is truncated to this length before sending to the AI provider to avoid token overruns. Default: 8000.'),
    ];

    // ── Assessment history ───────────────────────────────────────────────────
    $form['enable_history'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Keep assessment history'),
      '#default_value' => (bool) ($config->get('enable_history') ?? TRUE),
      '#description'   => $this->t('When enabled, all past assessments are stored. When disabled, only the latest assessment per node is kept.'),
    ];

    // ── Data retention limit ─────────────────────────────────────────────────
    $form['max_assessments_per_node'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Maximum assessments per node'),
      '#description'   => $this->t('Number of historical assessments to retain per node. Set to 0 to keep all. Older records are pruned during cron.'),
      '#default_value' => $config->get('max_assessments_per_node') ?? 10,
      '#min'           => 0,
    ];

    // ── Content extraction mode ──────────────────────────────────────────────
    $form['render_mode'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Content extraction mode'),
      '#options'       => [
        'text' => $this->t('Plain text (default)'),
        'html' => $this->t('Rendered HTML'),
      ],
      '#default_value' => $config->get('render_mode') ?? 'text',
      '#description'   => $this->t('How node content is extracted for AI analysis.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // array_filter removes unchecked checkboxes (Drupal returns 0 for unchecked).
    $node_types = array_keys(array_filter($form_state->getValue('node_types')));

    $this->config(self::CONFIG_NAME)
      ->set('enable_on_save', (bool) $form_state->getValue('assess_on_save'))
      ->set('node_types', $node_types)
      ->set('max_chars_per_request', (int) $form_state->getValue('max_chars_per_request'))
      ->set('enable_history', (bool) $form_state->getValue('enable_history'))
      ->set('max_assessments_per_node', (int) $form_state->getValue('max_assessments_per_node'))
      ->set('render_mode', $form_state->getValue('render_mode'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Returns options array of all node types keyed by machine name.
   */
  private function getNodeTypeOptions(): array {
    $types   = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $options = [];
    foreach ($types as $id => $type) {
      $options[$id] = $type->label();
    }
    return $options;
  }

}
