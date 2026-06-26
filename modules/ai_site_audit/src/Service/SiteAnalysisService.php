<?php

declare(strict_types=1);

namespace Drupal\ai_site_audit\Service;

use Drupal\ai_content_audit\Ai\AiProviderRegistryInterface;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Tier 3: AI-powered interpretation of sitewide statistics.
 *
 * Sends pre-computed statistics (not content) to an LLM for strategic analysis.
 * Cost is constant ~$0.02 regardless of site size because only
 * statistics are sent.
 */
class SiteAnalysisService {

  /**
   * KeyValue collection.
   */
  protected const KV_COLLECTION = 'ai_site_audit';

  /**
   * KeyValue key for AI interpretation.
   */
  protected const KV_KEY = 'ai_interpretation';

  /**
   * Default TTL for AI interpretation (24 hours).
   */
  protected const DEFAULT_TTL = 86400;

  public function __construct(
    protected AiProviderRegistryInterface $aiProvider,
    protected KeyValueExpirableFactoryInterface $keyValueFactory,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Analyze pre-computed statistics and technical audit results using AI.
   *
   * @param array $statistics
   *   The rollup statistics from SiteRollupService.
   * @param array $technicalAudit
   *   Results from TechnicalAuditService::runAllChecks().
   * @param array $filesystemAudit
   *   Results from FilesystemAuditService::runAllChecks().
   * @param array $options
   *   Optional overrides: provider_id, model_id, max_tokens.
   *
   * @return array
   *   The AI interpretation with keys: executive_summary, overall_grade,
   *   top_priorities, content_type_recommendations, patterns_identified,
   *   quick_wins, infrastructure_recommendations, benchmark_comparison,
   *   projected_improvement.
   */
  public function analyzeStatistics(array $statistics, array $technicalAudit, array $filesystemAudit = [], array $options = []): array {
    $canRun = $this->canRunAnalysis();
    if (!$canRun['allowed']) {
      $this->logger->warning('AI analysis blocked: @reason', ['@reason' => $canRun['reason']]);
      return ['error' => $canRun['reason']];
    }

    $systemMessage = $this->buildSystemMessage();
    $userMessage = $this->buildUserMessage($statistics, $technicalAudit, $filesystemAudit);

    try {
      // Resolve provider and model — same priority as AiAssessmentService:
      // 1. Runtime $options override
      // 2. Centrally configured 'content_audit' default in ai.settings
      // 3. Centrally configured generic 'chat' default in ai.settings.
      $central = $this->aiProvider->getDefaultProviderForOperationType('content_audit')
        ?? $this->aiProvider->getDefaultProviderForOperationType('chat');

      $providerId = $options['provider_id'] ?? $central['provider_id'] ?? NULL;
      $modelId    = $options['model_id'] ?? $central['model_id'] ?? NULL;

      if (!$providerId || !$modelId) {
        return ['error' => 'No AI chat provider configured. Please configure a default chat provider at /admin/config/ai/providers.'];
      }

      $input = new ChatInput([
        new ChatMessage('system', $systemMessage),
        new ChatMessage('user', $userMessage),
      ]);
      /** @var \Drupal\ai\Plugin\ProviderProxy&\Drupal\ai\OperationType\Chat\ChatInterface $proxy */
      $proxy = $this->aiProvider->createInstance($providerId);
      $response = $proxy->chat($input, $modelId, ['ai_site_audit', 'analyze']);
      $rawOutput = $response->getNormalized()->getText();

      $parsed = $this->parseJsonResponse($rawOutput);
      if ($parsed === NULL) {
        $this->logger->error('Failed to parse AI sitewide analysis response.');
        return ['error' => 'Failed to parse AI response.', 'raw_output' => $rawOutput];
      }

      // Add metadata.
      $parsed['analyzed_at'] = time();
      $parsed['provider_id'] = $providerId;
      $parsed['model_id'] = $modelId;

      // Increment daily AI call counter.
      $callsToday = (int) $this->state->get('ai_site_audit.ai_calls_today', 0);
      $this->state->set('ai_site_audit.ai_calls_today', $callsToday + 1);

      // Cache the interpretation.
      $this->cacheInterpretation($parsed);

      // Update state.
      $this->state->set('ai_site_audit.last_ai_analysis_time', time());

      $this->logger->info('AI sitewide analysis completed successfully.');

      return $parsed;
    }
    catch (\Exception $e) {
      $this->logger->error('AI sitewide analysis failed: @message', ['@message' => $e->getMessage()]);
      return ['error' => 'AI analysis failed: ' . $e->getMessage()];
    }
  }

  /**
   * Check whether an AI analysis can be run now.
   *
   * @return array
   *   Array with 'allowed' (bool) and 'reason' (string) keys.
   */
  public function canRunAnalysis(): array {
    $config = $this->configFactory->get('ai_site_audit.settings');
    $cooldownHours = (int) ($config->get('analysis_cooldown_hours') ?? 24);
    $lastAnalysis = (int) $this->state->get('ai_site_audit.last_ai_analysis_time', 0);

    if ($lastAnalysis > 0 && (time() - $lastAnalysis) < ($cooldownHours * 3600)) {
      $nextAllowed = $lastAnalysis + ($cooldownHours * 3600);
      return [
        'allowed' => FALSE,
        'reason' => 'Cooldown period active. Next analysis allowed at ' . date('Y-m-d H:i:s', $nextAllowed),
        'next_allowed_at' => $nextAllowed,
      ];
    }

    // Check daily AI call budget.
    $maxCalls = (int) ($config->get('max_ai_calls_per_analysis') ?: 20);
    $callsToday = (int) $this->state->get('ai_site_audit.ai_calls_today', 0);
    $callsDate = $this->state->get('ai_site_audit.ai_calls_date', '');
    $today = date('Ymd');

    // Reset counter if it's a new day.
    if ($callsDate !== $today) {
      $callsToday = 0;
      $this->state->set('ai_site_audit.ai_calls_date', $today);
      $this->state->set('ai_site_audit.ai_calls_today', 0);
    }

    if ($callsToday >= $maxCalls) {
      return [
        'allowed' => FALSE,
        'reason' => "Daily AI call budget exhausted ({$callsToday}/{$maxCalls}). Resets tomorrow.",
        'calls_today' => $callsToday,
        'max_calls' => $maxCalls,
      ];
    }

    // Check if AI providers are available.
    try {
      $providers = $this->aiProvider->getSimpleProviderModelOptions('chat');
      if (empty($providers)) {
        return ['allowed' => FALSE, 'reason' => 'No AI chat providers configured.'];
      }
    }
    catch (\Exception $e) {
      return ['allowed' => FALSE, 'reason' => 'AI provider check failed: ' . $e->getMessage()];
    }

    return ['allowed' => TRUE, 'reason' => 'Analysis can proceed.'];
  }

  /**
   * Get the current budget status for display.
   *
   * @return array
   *   Array with keys: calls_today, max_calls, tokens_budget,
   *   calls_remaining, budget_exhausted.
   */
  public function getBudgetStatus(): array {
    $config = $this->configFactory->get('ai_site_audit.settings');
    $maxCalls = (int) ($config->get('max_ai_calls_per_analysis') ?: 20);
    $maxTokens = (int) ($config->get('max_tokens_per_analysis') ?: 100000);

    $callsDate = $this->state->get('ai_site_audit.ai_calls_date', '');
    $today = date('Ymd');
    $callsToday = ($callsDate === $today) ? (int) $this->state->get('ai_site_audit.ai_calls_today', 0) : 0;

    return [
      'calls_today' => $callsToday,
      'max_calls' => $maxCalls,
      'tokens_budget' => $maxTokens,
      'calls_remaining' => max(0, $maxCalls - $callsToday),
      'budget_exhausted' => $callsToday >= $maxCalls,
    ];
  }

  /**
   * Build the system message for the AI interpretation prompt.
   */
  protected function buildSystemMessage(): string {
    return <<<'PROMPT'
You are a senior content strategist and SEO analyst reviewing a Drupal website.
You are analyzing pre-computed aggregate statistics from AI content assessments,
NOT individual pages. Your role is to interpret statistical patterns and provide
strategic recommendations.

IMPORTANT: You must respond with valid JSON only. No markdown, no explanation outside the JSON.

The JSON schema you must follow:
{
  "executive_summary": "<2-3 paragraph strategic narrative>",
  "overall_grade": "<A|B|C|D|F>",
  "top_priorities": [
    {
      "title": "<priority title>",
      "description": "<why this matters>",
      "affected_nodes": "<estimated count or percentage>",
      "potential_score_impact": "<estimated points improvement>"
    }
  ],
  "content_type_recommendations": [
    {
      "content_type": "<type machine name>",
      "recommendation": "<specific actionable advice>",
      "priority": "<high|medium|low>"
    }
  ],
  "patterns_identified": [
    {
      "pattern": "<description of the pattern>",
      "significance": "<why it matters>",
      "recommendation": "<what to do about it>"
    }
  ],
  "quick_wins": [
    {
      "title": "<action title>",
      "description": "<what to do>",
      "effort": "<low|medium>",
      "impact": "<estimated improvement>"
    }
  ],
  "infrastructure_recommendations": [
    {
      "area": "<robots.txt|llms.txt|sitemap|schema|etc>",
      "status": "<good|needs_attention|missing>",
      "recommendation": "<specific action>"
    }
  ],
  "benchmark_comparison": {
    "strengths": ["<strength 1>", "<strength 2>"],
    "weaknesses": ["<weakness 1>", "<weakness 2>"]
  },
  "projected_improvement": {
    "current_avg": "<number>",
    "projected_avg": "<number after fixes>",
    "confidence": "<low|medium|high>"
  }
}
PROMPT;
  }

  /**
   * Build the user message with pre-computed statistics.
   */
  protected function buildUserMessage(array $statistics, array $technicalAudit, array $filesystemAudit = []): string {
    $parts = ["Here are the sitewide content audit statistics for analysis:\n"];

    // Overall stats.
    $parts[] = "## Overall Statistics";
    $parts[] = sprintf("- Total assessed: %d", $statistics['total_assessed'] ?? 0);
    $parts[] = sprintf("- Coverage: %.1f%%", $statistics['coverage_pct'] ?? 0);
    $parts[] = sprintf("- Average score: %.1f/100", $statistics['avg_score'] ?? 0);

    // Score distribution.
    if (!empty($statistics['score_distribution'])) {
      $parts[] = "\n## Score Distribution";
      foreach ($statistics['score_distribution'] as $bucket => $count) {
        $parts[] = sprintf("- %s: %d nodes", $bucket, $count);
      }
    }

    // Sub-score averages.
    if (!empty($statistics['sub_score_averages'])) {
      $parts[] = "\n## Sub-Score Averages";
      foreach ($statistics['sub_score_averages'] as $dim => $data) {
        $parts[] = sprintf("- %s: %.1f/%d (%.1f%%)", $data['label'] ?? $dim, $data['avg'], $data['max_possible'], $data['pct']);
      }
    }

    // Top failing checkpoints.
    if (!empty($statistics['top_failing_checkpoints'])) {
      $parts[] = "\n## Top Failing Checkpoints (most common issues)";
      foreach (array_slice($statistics['top_failing_checkpoints'], 0, 10) as $cp) {
        $parts[] = sprintf("- %s: %d failures (%.1f%% of nodes) [%s priority]", $cp['item'], $cp['fail_count'], $cp['pct'], $cp['priority']);
      }
    }

    // Top action items.
    if (!empty($statistics['top_action_items'])) {
      $parts[] = "\n## Most Common Action Items";
      foreach (array_slice($statistics['top_action_items'], 0, 10) as $ai) {
        $parts[] = sprintf("- %s: recommended for %d nodes [%s priority]", $ai['title'], $ai['count'], $ai['priority']);
      }
    }

    // Checkpoint totals.
    if (!empty($statistics['checkpoint_status_totals'])) {
      $t = $statistics['checkpoint_status_totals'];
      $parts[] = "\n## Checkpoint Status Totals";
      $pass = (int) ($t['pass'] ?? 0);
      $fail = (int) ($t['fail'] ?? 0);
      $warning = (int) ($t['warning'] ?? 0);
      $total = $pass + $fail + $warning;
      $parts[] = sprintf("- Pass: %d (%.1f%%)", $pass, $total > 0 ? ($pass / $total) * 100 : 0);
      $parts[] = sprintf("- Fail: %d (%.1f%%)", $fail, $total > 0 ? ($fail / $total) * 100 : 0);
      $parts[] = sprintf("- Warning: %d (%.1f%%)", $warning, $total > 0 ? ($warning / $total) * 100 : 0);
    }

    // Technical audit results.
    if (!empty($technicalAudit)) {
      $parts[] = "\n## Technical Audit Results";
      foreach ($technicalAudit as $result) {
        if (is_array($result)) {
          $parts[] = sprintf("- %s: %s — %s", $result['label'] ?? $result['check'] ?? 'unknown', $result['status'] ?? 'unknown', $result['description'] ?? '');
        }
        elseif (is_object($result) && method_exists($result, 'toArray')) {
          $arr = $result->toArray();
          $parts[] = sprintf("- %s: %s — %s", $arr['label'] ?? $arr['check'] ?? 'unknown', $arr['status'] ?? 'unknown', $arr['description'] ?? '');
        }
      }
    }

    // Filesystem audit results.
    if (!empty($filesystemAudit)) {
      $parts[] = "\n## Filesystem Audit Results";
      $fsPass = 0;
      $fsFail = 0;
      $fsWarning = 0;
      $fsInfo = 0;
      $fsCritical = [];
      foreach ($filesystemAudit as $result) {
        $status = is_array($result) ? ($result['status'] ?? 'unknown') : 'unknown';
        $label = is_array($result) ? ($result['label'] ?? $result['check'] ?? 'unknown') : 'unknown';
        match ($status) {
          'pass' => $fsPass++,
          'fail' => $fsFail++,
          'warning' => $fsWarning++,
          'info' => $fsInfo++,
          default => NULL,
        };
        if ($status === 'fail') {
          $fsCritical[] = $label;
        }
      }
      $parts[] = sprintf("- Security/health checks: %d pass / %d fail / %d warning / %d info", $fsPass, $fsFail, $fsWarning, $fsInfo);
      if (!empty($fsCritical)) {
        $parts[] = "- Critical filesystem issues: " . implode(', ', array_slice($fsCritical, 0, 10));
      }
      // List individual filesystem audit results.
      foreach ($filesystemAudit as $result) {
        if (is_array($result)) {
          $parts[] = sprintf("- %s: %s — %s", $result['label'] ?? $result['check'] ?? 'unknown', $result['status'] ?? 'unknown', $result['description'] ?? '');
        }
      }
    }

    $parts[] = "\nPlease analyze these statistics and provide your strategic assessment as JSON.";

    return implode("\n", $parts);
  }

  /**
   * Get cached AI interpretation if available.
   */
  public function getCachedInterpretation(): ?array {
    $kv = $this->keyValueFactory->get(self::KV_COLLECTION);
    $data = $kv->get(self::KV_KEY);
    return is_array($data) ? $data : NULL;
  }

  /**
   * Cache the AI interpretation result.
   */
  protected function cacheInterpretation(array $result): void {
    $kv = $this->keyValueFactory->get(self::KV_COLLECTION);
    $kv->setWithExpire(self::KV_KEY, $result, self::DEFAULT_TTL);
  }

  /**
   * Parse a JSON response from the AI, handling markdown fences.
   *
   * @param string $raw
   *   The raw response text.
   *
   * @return array|null
   *   Parsed array or NULL on failure.
   */
  public function parseJsonResponse(string $raw): ?array {
    $raw = trim($raw);

    // Try direct parse.
    $result = json_decode($raw, TRUE);
    if (is_array($result)) {
      return $result;
    }

    // Strip markdown code fences.
    if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $raw, $matches)) {
      $result = json_decode(trim($matches[1]), TRUE);
      if (is_array($result)) {
        return $result;
      }
    }

    // Try to find JSON object.
    if (preg_match('/\{.*\}/s', $raw, $matches)) {
      $result = json_decode($matches[0], TRUE);
      if (is_array($result)) {
        return $result;
      }
    }

    return NULL;
  }

}
