<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Service;

use Drupal\ai_content_audit\Ai\AiProviderRegistryInterface;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_content_audit\Enum\RenderMode;
use Drupal\ai_content_audit\Extractor\ContentExtractorManager;
use Drupal\ai_content_audit\Service\ProviderModelChoices;
use Drupal\ai_content_audit_scoring\Entity\AiContentAssessment;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Handles AI-powered content assessment for nodes.
 */
class AiAssessmentService {

  use StringTranslationTrait;

  /**
   * JSON schema describing the structured assessment API response.
   */
  const RESPONSE_SCHEMA = <<<'JSON'
{
  "ai_readiness_score": "<integer 0-100>",
  "qualitative_status": "<Needs Work | Improving | AI Ready>",
  "readability": {
    "grade_level": "<number>",
    "assessment": "<string>"
  },
  "seo": {
    "title_present": "<bool>",
    "meta_description_present": "<bool>",
    "suggested_meta": "<string>",
    "open_graph_present": "<bool>",
    "canonical_present": "<bool>"
  },
  "content_completeness": {
    "missing_topics": ["<string>"],
    "word_count_adequate": "<bool>",
    "has_lead_paragraph": "<bool>"
  },
  "tone_consistency": {
    "tone": "<string>",
    "confidence": "<number 0-1>"
  },
  "sub_scores": [
    {
      "dimension": "<technical_seo | content_quality | schema_markup>",
      "label": "<human-readable label>",
      "score": "<integer>",
      "max_score": "<integer>"
    }
  ],
  "heading_hierarchy": {
    "has_single_h1": "<boolean - true if exactly one H1 exists>",
    "hierarchy_valid": "<boolean - true if headings follow proper nesting H1>H2>H3 without skipping levels>",
    "total_headings": "<integer>",
    "assessment": "<string - brief assessment of heading structure quality>"
  },
  "image_accessibility": {
    "total_images": "<integer>",
    "images_with_alt": "<integer>",
    "images_without_alt": "<integer>",
    "alt_quality": "<string - assessment of alt text quality: descriptive, generic, or missing>",
    "assessment": "<string - brief assessment of image accessibility>"
  },
  "link_analysis": {
    "internal_links": "<integer>",
    "external_links": "<integer>",
    "total_links": "<integer>",
    "has_meaningful_anchors": "<boolean - true if anchor text is descriptive, not just 'click here'>",
    "assessment": "<string - brief assessment of link quality and density>"
  },
  "content_freshness": {
    "days_since_modified": "<integer or null if dates not available>",
    "is_stale": "<boolean - true if content appears outdated based on dates and content signals>",
    "assessment": "<string - assessment of content timeliness>"
  },
  "entity_richness": {
    "has_author": "<boolean - true if a named author is present>",
    "has_taxonomy": "<boolean - true if taxonomy terms are assigned>",
    "has_related_content": "<boolean - true if entity references to other content exist>",
    "assessment": "<string - assessment of content contextualization and entity relationships>"
  },
  "content_patterns": {
    "has_qa_structure": false,
    "qa_pair_count": 0,
    "has_definition_patterns": false,
    "definition_count": 0,
    "has_tldr_section": false,
    "tldr_location": "none",
    "has_key_takeaways": false,
    "has_summary_section": false,
    "has_direct_answer_first": false,
    "assessment": "<string describing the content pattern quality>"
  },
  "rag_chunk_quality": {
    "h2_section_count": 0,
    "sections_optimal_length": 0,
    "sections_too_short": 0,
    "sections_too_long": 0,
    "sections_self_contained": 0,
    "average_section_length_estimate": 0,
    "has_topic_sentences": false,
    "assessment": "<string describing RAG chunk quality>"
  },
  "checkpoints": [
    {
      "category": "<Content Structure | Metadata | Technical | Schema | Accessibility | Content Patterns>",
      "item": "<checkpoint description>",
      "status": "<pass | fail | warning | info>",
      "priority": "<high | medium | low>"
    }
  ],
  "action_items": [
    {
      "id": "<snake_case_identifier>",
      "priority": "<high | medium | low>",
      "title": "<short action title>",
      "description": "<why this matters and what to do>",
      "field_target": "<body | meta_description | schema | og_image | heading_structure | image_alt | links | null>",
      "suggested_content": "<optional suggested text or null>"
    }
  ],
  "suggestions": [
    {
      "area": "<string>",
      "suggestion": "<string>",
      "priority": "<low | medium | high>"
    }
  ],
  "provider_metadata": {
    "provider_id": "<string>",
    "model": "<string>",
    "timestamp": "<ISO8601 string>"
  }
}
JSON;

  public function __construct(
    protected AiProviderRegistryInterface $aiProvider,
    protected ContentExtractorManager $extractorManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScoringPromptResolver $promptResolver,
    protected ProviderModelChoices $providerModelChoices,
  ) {}

  /**
   * Runs an AI assessment on the given node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node revision to assess. Callers must pass the revision whose content
   *   should be extracted (for example the default revision on the canonical
   *   route, or a specific revision from
   *   \Drupal\Core\Entity\RevisionableStorageInterface::loadRevision()). The
   *   HTML extractor uses this object for Layout Builder and entity view
   *   rendering; passing the wrong revision assesses the wrong layout/content.
   * @param array $options
   *   Optional overrides:
   *   - 'provider_id' (string): AI provider machine name.
   *   - 'model_id' (string): AI model machine name.
   *   - 'render_mode' (string):
   *     How to extract content for assessment. RenderMode enum values:
   *     'text' (default), 'html', 'screenshot'.
   *     See \Drupal\ai_content_audit\Enum\RenderMode.
   *
   * @return array
   *   Array with keys: 'raw_output', 'parsed', 'success', 'error'.
   */
  public function assessNode(NodeInterface $node, array $options = []): array {
    $logger = $this->loggerFactory->get('ai_content_audit');

    // Check that a chat provider is available.
    if (!$this->aiProvider->hasProvidersForOperationType('chat')) {
      $providers_url = Url::fromRoute('ai.admin_providers')->toString();
      $message = $this->t('No AI chat provider is configured for the ai_content_audit module. Please install an AI provider module and configure it at @url.', ['@url' => $providers_url]);
      $logger->error($message);
      return ['success' => FALSE, 'error' => $message, 'raw_output' => '', 'parsed' => NULL];
    }

    $previewConfig = $this->configFactory->get('ai_content_audit.settings');
    $scoringConfig = $this->configFactory->get('ai_content_audit_scoring.settings');

    // Resolve provider and model — priority order:
    // 1. Runtime $options override (e.g. from Drush --provider / --model flags)
    // 2. Module-level default shared with AIRO Preview.
    // 3. Centrally configured 'content_audit' default in ai.settings
    // 4. Centrally configured generic 'chat' default in ai.settings.
    $provider_id = '';
    $model_id = '';
    $proxy = NULL;
    if (!empty($options['provider_id'])) {
      $provider_id = (string) $options['provider_id'];
      $model_id = (string) ($options['model_id'] ?? '');
      try {
        $proxy = $this->aiProvider->createInstance($provider_id);
      }
      catch (\Throwable $e) {
        $message = 'The selected AI provider is not available: ' . $e->getMessage();
        $logger->error($message);
        return ['success' => FALSE, 'error' => $message, 'raw_output' => '', 'parsed' => NULL];
      }
    }
    elseif ($previewConfig->get('default_provider_model')) {
      $default_provider_model = (string) $previewConfig->get('default_provider_model');
      [$proxy, $model_id] = $this->providerModelChoices->loadFromKey($default_provider_model);
      [$provider_id] = $this->providerModelChoices->parseKey($default_provider_model);
    }
    else {
      $central = $this->aiProvider->getDefaultProviderForOperationType('content_audit')
        ?? $this->aiProvider->getDefaultProviderForOperationType('chat');
      $provider_id = (string) ($central['provider_id'] ?? '');
      $model_id = (string) ($central['model_id'] ?? '');
      if ($provider_id !== '') {
        try {
          $proxy = $this->aiProvider->createInstance($provider_id);
        }
        catch (\Throwable $e) {
          $message = 'The default AI provider is not available: ' . $e->getMessage();
          $logger->error($message);
          return ['success' => FALSE, 'error' => $message, 'raw_output' => '', 'parsed' => NULL];
        }
      }
    }

    if ($proxy === NULL || $provider_id === '' || $model_id === '') {
      $message = 'Could not resolve an available AI provider/model for chat.';
      $logger->error($message);
      return ['success' => FALSE, 'error' => $message, 'raw_output' => '', 'parsed' => NULL];
    }

    // Extract content from the node using the configured render mode.
    $renderMode = $options['render_mode'] ?? RenderMode::default()->value;
    $content = $this->extractorManager->getExtractorForMode($renderMode)->extract($node);
    if (empty(trim($content))) {
      $message = 'No extractable content found on node @nid.';
      $logger->warning($message, ['@nid' => $node->id()]);
      return ['success' => FALSE, 'error' => 'No content to assess.', 'raw_output' => '', 'parsed' => NULL];
    }

    // Truncate to configured max chars to avoid token overflows.
    $max_chars = (int) ($previewConfig->get('max_chars_per_request') ?: 8000);
    if (mb_strlen($content) > $max_chars) {
      $content = mb_substr($content, 0, $max_chars) . "\n[Content truncated for assessment]";
    }

    try {
      ['system_prompt' => $system_message, 'user_prompt' => $user_message] = $this->promptResolver->resolveAssessmentPrompts([
        'DETERMINISTIC_SIGNALS' => $this->buildDeterministicSignals($content),
        'SEO_SIGNALS' => $this->buildNodeSeoSignals($node),
        'CONTENT' => $content,
        'RESPONSE_SCHEMA' => self::RESPONSE_SCHEMA,
      ]);
    }
    catch (\RuntimeException $e) {
      $logger->error('Assessment prompt resolution failed for node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return ['success' => FALSE, 'error' => $e->getMessage(), 'raw_output' => '', 'parsed' => NULL];
    }

    // Build ChatInput.
    $chat_input = new ChatInput([
      new ChatMessage('system', $system_message),
      new ChatMessage('user', $user_message),
    ]);

    // Call the provider.
    try {
      $output = $proxy->chat($chat_input, $model_id, ['ai_content_audit', 'assess']);
      $raw_text = $output->getNormalized()->getText();
    }
    catch (\Throwable $e) {
      $logger->error('AI provider call failed for node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return ['success' => FALSE, 'error' => $e->getMessage(), 'raw_output' => '', 'parsed' => NULL];
    }

    // Parse the JSON response.
    $parsed = $this->parseJsonResponse($raw_text);
    if ($parsed === NULL) {
      $logger->warning('Failed to parse AI response JSON for node @nid. Raw: @raw', [
        '@nid' => $node->id(),
        '@raw' => mb_substr($raw_text, 0, 500),
      ]);
      return [
        'success' => FALSE,
        'error' => 'Failed to parse JSON response from AI provider.',
        'raw_output' => $raw_text,
        'parsed' => NULL,
      ];
    }

    // Enforce enable_history: when disabled, delete all prior assessments for
    // this node so only the latest result is retained.
    $storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    if (!($scoringConfig->get('enable_history') ?? TRUE)) {
      $existing_ids = $storage->getQuery()
        ->condition('target_node', $node->id())
        ->accessCheck(FALSE)
        ->execute();

      if ($existing_ids) {
        $storage->delete($storage->loadMultiple($existing_ids));
      }
    }

    // Detect whether the AI returned a v2 response (has the new fields).
    $is_v2 = isset($parsed['sub_scores']) || isset($parsed['checkpoints']) || isset($parsed['action_items']);
    if (!$is_v2) {
      $logger->warning('AI response for node @nid is missing v2-specific fields (sub_scores, checkpoints, action_items). Saving with v1 fields only.', [
        '@nid' => $node->id(),
      ]);
    }

    // Ensure backward compatibility: provide sensible defaults for new
    // content_patterns and rag_chunk_quality dimensions if the LLM omitted them
    // (e.g., older cached prompts or providers that truncate the schema).
    if (!isset($parsed['content_patterns']) || !is_array($parsed['content_patterns'])) {
      $parsed['content_patterns'] = [
        'has_qa_structure' => FALSE,
        'qa_pair_count' => 0,
        'has_definition_patterns' => FALSE,
        'definition_count' => 0,
        'has_tldr_section' => FALSE,
        'tldr_location' => 'none',
        'has_key_takeaways' => FALSE,
        'has_summary_section' => FALSE,
        'has_direct_answer_first' => FALSE,
        'assessment' => '',
      ];
    }
    if (!isset($parsed['rag_chunk_quality']) || !is_array($parsed['rag_chunk_quality'])) {
      $parsed['rag_chunk_quality'] = [
        'h2_section_count' => 0,
        'sections_optimal_length' => 0,
        'sections_too_short' => 0,
        'sections_too_long' => 0,
        'sections_self_contained' => 0,
        'average_section_length_estimate' => 0,
        'has_topic_sentences' => FALSE,
        'assessment' => '',
      ];
    }

    // Save the assessment as a new AiContentAssessment entity.
    try {
      $score = max(0, min(100, (int) ($parsed['ai_readiness_score'] ?? 0)));
      $assessment = $storage->create([
        'target_node' => $node->id(),
        'provider_id' => $provider_id,
        'model_id'    => $model_id,
        'score'       => $score,
        'result_json' => json_encode($parsed),
        'raw_output'  => $raw_text,
      ]);
      if (!$assessment instanceof AiContentAssessment) {
        throw new \UnexpectedValueException('Assessment storage created an unexpected entity type.');
      }

      // Populate v2 fields when present in the response; leave NULL otherwise
      // so that existing records are not broken.
      if ($is_v2) {
        if (isset($parsed['sub_scores'])) {
          $assessment->setSubScores($parsed['sub_scores']);
        }
        if (isset($parsed['checkpoints'])) {
          $assessment->setCheckpoints($parsed['checkpoints']);
        }
        if (isset($parsed['action_items'])) {
          $assessment->setActionItems($parsed['action_items']);
        }
      }

      // Compute score trend delta against the most recent previous assessment.
      $previous = $this->getLatestAssessmentForNode($node);
      if ($previous && $previous->getScore() !== NULL) {
        $delta = $score - $previous->getScore();
        $assessment->setScoreTrendDelta($delta);
      }

      $assessment->save();
    }
    catch (\Throwable $e) {
      $logger->error('Failed to save assessment entity for node @nid: @message', [
        '@nid'     => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'error' => 'Failed to save assessment.',
        'raw_output' => $raw_text,
        'parsed' => $parsed,
      ];
    }

    return [
      'success' => TRUE,
      'error' => NULL,
      'raw_output' => $raw_text,
      'parsed' => $parsed,
    ];
  }

  /**
   * Returns the most recent saved assessment for a node, or NULL if none exist.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to look up.
   *
   * @return \Drupal\ai_content_audit_scoring\Entity\AiContentAssessment|null
   *   The latest assessment entity, or NULL.
   */
  protected function getLatestAssessmentForNode(NodeInterface $node): ?AiContentAssessment {
    $storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    $ids = $storage->getQuery()
      ->condition('target_node', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $entity = $storage->load(reset($ids));
    return $entity instanceof AiContentAssessment ? $entity : NULL;
  }

  /**
   * Builds deterministic structure signals from markerized content.
   */
  protected function buildDeterministicSignals(string $content): string {
    $h1 = preg_match_all('/^# H1:\s+/m', $content);
    $h2 = preg_match_all('/^## H2:\s+/m', $content);
    $hasSummary = preg_match(
      '/\b(summary|key takeaways|takeaways|tl;dr|in summary|what you\'ll learn|highlights?)\b/i',
      $content
    ) === 1;

    return implode("\n", [
      '- h1_markers_count: ' . (int) $h1,
      '- h2_markers_count: ' . (int) $h2,
      '- has_summary_takeaways_keywords: ' . ($hasSummary ? 'true' : 'false'),
    ]);
  }

  /**
   * Builds deterministic SEO signals from computed metatag field values.
   */
  protected function buildNodeSeoSignals(NodeInterface $node): string {
    $hasDescription = FALSE;
    $hasCanonical = FALSE;
    $hasJsonLdSchema = FALSE;

    if ($node->hasField('metatag') && !$node->get('metatag')->isEmpty()) {
      $items = $node->get('metatag')->getValue();
      foreach ($items as $item) {
        $tag = strtolower((string) ($item['tag'] ?? ''));
        $attrs = is_array($item['attributes'] ?? NULL) ? $item['attributes'] : [];
        $name = strtolower((string) ($attrs['name'] ?? ''));
        $rel = strtolower((string) ($attrs['rel'] ?? ''));
        $type = strtolower((string) ($attrs['type'] ?? ''));
        $content = trim((string) ($attrs['content'] ?? ''));

        if ($tag === 'meta' && $name === 'description' && $content !== '') {
          $hasDescription = TRUE;
        }
        if ($tag === 'link' && $rel === 'canonical') {
          $hasCanonical = TRUE;
        }
        if ($tag === 'script' && $type === 'application/ld+json') {
          $hasJsonLdSchema = TRUE;
        }
      }
    }

    return implode("\n", [
      '- meta_description_present: ' . ($hasDescription ? 'true' : 'false'),
      '- canonical_tag_present: ' . ($hasCanonical ? 'true' : 'false'),
      '- jsonld_schema_present: ' . ($hasJsonLdSchema ? 'true' : 'false'),
    ]);
  }

  /**
   * Attempts to parse a JSON string from LLM output.
   *
   * Tries direct decode, then attempts to extract JSON substring.
   *
   * @param string $raw
   *   Raw text from the LLM.
   *
   * @return array|null
   *   Decoded array or NULL on failure.
   */
  public function parseJsonResponse(string $raw): ?array {
    // Attempt 1: direct decode.
    $decoded = json_decode(trim($raw), TRUE);
    if (is_array($decoded)) {
      return $decoded;
    }

    // Attempt 2: strip markdown code fences if present.
    $stripped = preg_replace('/^```(?:json)?\s*/m', '', $raw);
    $stripped = preg_replace('/\s*```$/m', '', $stripped);
    $decoded = json_decode(trim($stripped), TRUE);
    if (is_array($decoded)) {
      return $decoded;
    }

    // Attempt 3: extract first { ... } block.
    if (preg_match('/(\{.+\})/s', $raw, $matches)) {
      $decoded = json_decode($matches[1], TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
    }

    return NULL;
  }

  /**
   * Corrects heading-related fields/checkpoints using deterministic markers.
   *
   * @param string $content
   *   Markerized extracted content.
   * @param array<string, mixed> $parsed
   *   Parsed LLM response (mutated in place).
   */
  protected function applyDeterministicHeadingCorrections(string $content, array &$parsed): void {
    $h1Count = (int) preg_match_all('/^# H1:\s+/m', $content);
    $h2Count = (int) preg_match_all('/^## H2:\s+/m', $content);
    $hasHeadings = ($h1Count + $h2Count) > 0;

    if (!isset($parsed['heading_hierarchy']) || !is_array($parsed['heading_hierarchy'])) {
      $parsed['heading_hierarchy'] = [];
    }

    $parsed['heading_hierarchy']['total_headings'] = max(
      (int) ($parsed['heading_hierarchy']['total_headings'] ?? 0),
      $h1Count + $h2Count
    );
    $parsed['heading_hierarchy']['has_single_h1'] = ($h1Count === 1);
    if ($hasHeadings && !isset($parsed['heading_hierarchy']['assessment'])) {
      $parsed['heading_hierarchy']['assessment'] = 'Heading markers detected from rendered content.';
    }

    if (!isset($parsed['checkpoints']) || !is_array($parsed['checkpoints'])) {
      return;
    }

    foreach ($parsed['checkpoints'] as &$checkpoint) {
      if (!is_array($checkpoint)) {
        continue;
      }
      $item = strtolower((string) ($checkpoint['item'] ?? ''));
      $isHeadingCheckpoint = str_contains($item, 'heading') || str_contains($item, 'h1') || str_contains($item, 'h2');
      $isNegativeHeadingClaim = str_contains($item, 'no h1')
        || str_contains($item, 'no h2')
        || str_contains($item, 'no heading')
        || str_contains($item, 'missing h1')
        || str_contains($item, 'missing h2');

      if ($hasHeadings && $isHeadingCheckpoint && $isNegativeHeadingClaim) {
        $checkpoint['status'] = 'pass';
        $checkpoint['priority'] = 'low';
        $checkpoint['item'] = sprintf('Heading markers present (H1=%d, H2=%d)', $h1Count, $h2Count);
      }
    }
    unset($checkpoint);
  }

}
