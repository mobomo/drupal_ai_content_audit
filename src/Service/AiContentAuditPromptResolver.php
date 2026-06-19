<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai\Entity\AiPromptInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves configured AI Prompt entities and renders prompt variables.
 */
class AiContentAuditPromptResolver {

  public const DEFAULT_ASSESSMENT_SYSTEM_PROMPT = 'content_audit_assessment_system__content_audit_assessment_system_default';
  public const DEFAULT_ASSESSMENT_USER_PROMPT = 'content_audit_assessment_user__content_audit_assessment_user_default';
  public const DEFAULT_PREVIEW_SYSTEM_PROMPT = 'content_audit_preview_system__content_audit_preview_system_default';
  public const DEFAULT_PREVIEW_USER_PROMPT = 'content_audit_preview_user__content_audit_preview_user_default';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Resolves the assessment system and user prompts.
   *
   * @param array<string, string> $variables
   *   Prompt variables for the assessment user prompt.
   *
   * @return array{system_prompt: string, user_prompt: string}
   *   Rendered prompt text.
   */
  public function resolveAssessmentPrompts(array $variables): array {
    return [
      'system_prompt' => $this->resolve(
        'prompts.assessment_system_prompt',
        self::DEFAULT_ASSESSMENT_SYSTEM_PROMPT
      ),
      'user_prompt' => $this->resolve(
        'prompts.assessment_user_prompt',
        self::DEFAULT_ASSESSMENT_USER_PROMPT,
        $variables,
        [
          'DETERMINISTIC_SIGNALS',
          'SEO_SIGNALS',
          'CONTENT',
          'RESPONSE_SCHEMA',
        ]
      ),
    ];
  }

  /**
   * Resolves the preview system and user prompts.
   *
   * @param array<string, string> $variables
   *   Prompt variables for the preview user prompt.
   *
   * @return array{system_prompt: string, user_prompt: string}
   *   Rendered prompt text.
   */
  public function resolvePreviewPrompts(array $variables): array {
    return [
      'system_prompt' => $this->resolve(
        'prompts.preview_system_prompt',
        self::DEFAULT_PREVIEW_SYSTEM_PROMPT
      ),
      'user_prompt' => $this->resolve(
        'prompts.preview_user_prompt',
        self::DEFAULT_PREVIEW_USER_PROMPT,
        $variables,
        [
          'PAGE_CONTENT',
          'VISITOR_QUESTION',
        ]
      ),
    ];
  }

  /**
   * Loads and renders a configured prompt entity.
   *
   * @param string $configKey
   *   Dotted config key under ai_content_audit.settings.
   * @param string $defaultPromptId
   *   Default prompt entity ID when the config key is empty.
   * @param array<string, string> $variables
   *   Variables to replace in the prompt body.
   * @param string[] $requiredVariables
   *   Variables that must be provided and consumed by the prompt.
   *
   * @throws \RuntimeException
   *   Thrown when the configured prompt is missing or invalid.
   */
  private function resolve(
    string $configKey,
    string $defaultPromptId,
    array $variables = [],
    array $requiredVariables = [],
  ): string {
    $promptId = (string) ($this->configFactory
      ->get('ai_content_audit.settings')
      ->get($configKey) ?: $defaultPromptId);

    $prompt = $this->loadPrompt($promptId, $configKey);
    $text = $prompt->getPrompt();

    foreach ($requiredVariables as $variable) {
      if (!array_key_exists($variable, $variables)) {
        throw new \RuntimeException(sprintf(
          'The configured AI Content Audit prompt "%s" requires the missing %s variable.',
          $promptId,
          $variable
        ));
      }
      if (!$this->containsVariable($text, $variable)) {
        throw new \RuntimeException(sprintf(
          'The configured AI Content Audit prompt "%s" must include the %s variable.',
          $promptId,
          $variable
        ));
      }
    }

    foreach ($variables as $name => $value) {
      $text = str_replace(
        [
          '{' . $name . '}',
          '{{' . $name . '}}',
          '{{ ' . $name . ' }}',
        ],
        (string) $value,
        $text
      );
    }

    foreach ($requiredVariables as $variable) {
      if ($this->containsVariable($text, $variable)) {
        throw new \RuntimeException(sprintf(
          'The configured AI Content Audit prompt "%s" still contains the unreplaced %s variable.',
          $promptId,
          $variable
        ));
      }
    }

    return $text;
  }

  /**
   * Loads a prompt entity by ID.
   */
  private function loadPrompt(string $promptId, string $configKey): AiPromptInterface {
    $storage = $this->entityTypeManager->getStorage('ai_prompt');
    $prompt = $storage->load($promptId);

    if (!$prompt instanceof AiPromptInterface) {
      throw new \RuntimeException(sprintf(
        'The AI Content Audit setting "%s" references missing AI Prompt entity "%s". Select a valid prompt on the settings form or run database updates.',
        $configKey,
        $promptId
      ));
    }

    return $prompt;
  }

  /**
   * Checks whether a rendered prompt still contains a variable placeholder.
   */
  private function containsVariable(string $text, string $variable): bool {
    return str_contains($text, '{' . $variable . '}')
      || str_contains($text, '{{' . $variable . '}}')
      || str_contains($text, '{{ ' . $variable . ' }}');
  }

}
