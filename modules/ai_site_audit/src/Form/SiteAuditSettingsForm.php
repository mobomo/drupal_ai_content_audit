<?php

declare(strict_types=1);

namespace Drupal\ai_site_audit\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for AI Site Audit settings.
 */
class SiteAuditSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_site_audit.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_site_audit_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_site_audit.settings');

    // Analysis settings.
    $form['analysis'] = [
      '#type' => 'details',
      '#title' => $this->t('Analysis Settings'),
      '#open' => TRUE,
    ];

    $form['analysis']['analysis_tier_default'] = [
      '#type' => 'select',
      '#title' => $this->t('Default analysis tier'),
      '#description' => $this->t('The maximum tier to run when analysis is triggered. Tier 1 = SQL stats only, Tier 2 = SQL + PHP rollup, Tier 3 = SQL + PHP + AI interpretation.'),
      '#options' => [
        'tier_1' => $this->t('Tier 1 — SQL statistics only (free, instant)'),
        'tier_2' => $this->t('Tier 2 — Statistics + PHP rollup (free, ~10s)'),
        'tier_3' => $this->t('Tier 3 — Full analysis with AI insights (~$0.02)'),
      ],
      '#default_value' => $config->get('analysis_tier_default') ?: 'tier_2',
    ];

    $form['analysis']['auto_analysis_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Auto-analysis threshold'),
      '#description' => $this->t('Fraction of new assessments (relative to total) that triggers automatic re-analysis. E.g., 0.10 = 10%.'),
      '#min' => 0.01,
      '#max' => 1.0,
      '#step' => 0.01,
      '#default_value' => $config->get('auto_analysis_threshold') ?: 0.10,
    ];

    // AI Budget controls.
    $form['budget'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Budget Controls'),
      '#open' => TRUE,
    ];

    $form['budget']['max_ai_calls_per_analysis'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum AI calls per analysis'),
      '#description' => $this->t('Safety cap on the number of AI API calls per sitewide analysis run.'),
      '#min' => 1,
      '#max' => 100,
      '#default_value' => $config->get('max_ai_calls_per_analysis') ?: 20,
    ];

    $form['budget']['max_tokens_per_analysis'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum tokens per analysis'),
      '#description' => $this->t('Safety cap on total tokens used per sitewide analysis.'),
      '#min' => 1000,
      '#max' => 1000000,
      '#step' => 1000,
      '#default_value' => $config->get('max_tokens_per_analysis') ?: 100000,
    ];

    $form['budget']['analysis_cooldown_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Analysis cooldown (hours)'),
      '#description' => $this->t('Minimum hours between AI-powered (Tier 3) analyses.'),
      '#min' => 1,
      '#max' => 168,
      '#default_value' => $config->get('analysis_cooldown_hours') ?: 24,
    ];

    // Processing settings.
    $form['processing'] = [
      '#type' => 'details',
      '#title' => $this->t('Processing Settings'),
      '#open' => FALSE,
    ];

    $form['processing']['rollup_batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Rollup batch size'),
      '#description' => $this->t('Number of assessment records processed per batch during PHP rollup.'),
      '#min' => 100,
      '#max' => 5000,
      '#step' => 100,
      '#default_value' => $config->get('rollup_batch_size') ?: 500,
    ];

    $form['processing']['rollup_max_age_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Rollup max age (hours)'),
      '#description' => $this->t('Maximum age of the cached rollup before it is refreshed.'),
      '#min' => 1,
      '#max' => 168,
      '#default_value' => $config->get('rollup_max_age_hours') ?: 1,
    ];

    // Cache settings.
    $form['cache'] = [
      '#type' => 'details',
      '#title' => $this->t('Cache Settings'),
      '#open' => FALSE,
    ];

    $form['cache']['dashboard_cache_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Dashboard cache max-age (seconds)'),
      '#description' => $this->t('How long the rendered dashboard page is cached.'),
      '#min' => 0,
      '#max' => 86400,
      '#default_value' => $config->get('dashboard_cache_max_age') ?: 300,
    ];

    $form['cache']['stats_cache_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Statistics cache max-age (seconds)'),
      '#description' => $this->t('How long Tier 1 SQL statistics are cached.'),
      '#min' => 0,
      '#max' => 86400,
      '#default_value' => $config->get('stats_cache_max_age') ?: 300,
    ];

    // Cron settings.
    $form['cron'] = [
      '#type' => 'details',
      '#title' => $this->t('Cron Settings'),
      '#open' => FALSE,
    ];

    $form['cron']['enable_cron_analysis'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable periodic cron-based analysis'),
      '#description' => $this->t('Automatically trigger sitewide analysis during cron runs.'),
      '#default_value' => $config->get('enable_cron_analysis') ?: FALSE,
    ];

    $form['cron']['cron_analysis_frequency_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Cron analysis frequency (hours)'),
      '#description' => $this->t('Minimum hours between cron-triggered analyses.'),
      '#min' => 1,
      '#max' => 168,
      '#default_value' => $config->get('cron_analysis_frequency_hours') ?: 24,
      '#states' => [
        'visible' => [
          ':input[name="enable_cron_analysis"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Trend settings.
    $form['trends'] = [
      '#type' => 'details',
      '#title' => $this->t('Historical Trends'),
      '#open' => FALSE,
    ];

    $form['trends']['enable_trend_snapshots'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable historical trend snapshots'),
      '#description' => $this->t('Store daily score snapshots for trend analysis.'),
      '#default_value' => $config->get('enable_trend_snapshots') ?: TRUE,
    ];

    $form['trends']['trend_snapshot_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Trend snapshot retention (days)'),
      '#description' => $this->t('Number of days to retain historical snapshots.'),
      '#min' => 7,
      '#max' => 365,
      '#default_value' => $config->get('trend_snapshot_retention_days') ?: 90,
      '#states' => [
        'visible' => [
          ':input[name="enable_trend_snapshots"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Export settings.
    $form['export'] = [
      '#type' => 'details',
      '#title' => $this->t('Export Settings'),
      '#open' => FALSE,
    ];

    $form['export']['enable_csv_export'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable CSV export'),
      '#default_value' => $config->get('enable_csv_export') ?: TRUE,
    ];

    $form['export']['enable_json_export'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable JSON export'),
      '#default_value' => $config->get('enable_json_export') ?: TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_site_audit.settings')
      ->set('analysis_tier_default', $form_state->getValue('analysis_tier_default'))
      ->set('max_ai_calls_per_analysis', (int) $form_state->getValue('max_ai_calls_per_analysis'))
      ->set('max_tokens_per_analysis', (int) $form_state->getValue('max_tokens_per_analysis'))
      ->set('analysis_cooldown_hours', (int) $form_state->getValue('analysis_cooldown_hours'))
      ->set('auto_analysis_threshold', (float) $form_state->getValue('auto_analysis_threshold'))
      ->set('rollup_batch_size', (int) $form_state->getValue('rollup_batch_size'))
      ->set('rollup_max_age_hours', (int) $form_state->getValue('rollup_max_age_hours'))
      ->set('dashboard_cache_max_age', (int) $form_state->getValue('dashboard_cache_max_age'))
      ->set('stats_cache_max_age', (int) $form_state->getValue('stats_cache_max_age'))
      ->set('enable_cron_analysis', (bool) $form_state->getValue('enable_cron_analysis'))
      ->set('cron_analysis_frequency_hours', (int) $form_state->getValue('cron_analysis_frequency_hours'))
      ->set('enable_trend_snapshots', (bool) $form_state->getValue('enable_trend_snapshots'))
      ->set('trend_snapshot_retention_days', (int) $form_state->getValue('trend_snapshot_retention_days'))
      ->set('enable_csv_export', (bool) $form_state->getValue('enable_csv_export'))
      ->set('enable_json_export', (bool) $form_state->getValue('enable_json_export'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
