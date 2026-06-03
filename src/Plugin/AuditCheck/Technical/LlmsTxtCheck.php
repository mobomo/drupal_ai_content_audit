<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Technical;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\Plugin\AuditCheck\AuditCheckBase;
use Drupal\ai_content_audit\Service\HtmlFetchService;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Validates the /llms.txt file conforms to the llmstxt.org specification.
 */
#[AuditCheck(
  id: 'llms_txt',
  label: new TranslatableMarkup('LLMs.txt'),
  description: new TranslatableMarkup('Validates the /llms.txt file conforms to the llmstxt.org specification.'),
  scope: 'site',
  category: 'AI Signals',
)]
class LlmsTxtCheck extends AuditCheckBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly HtmlFetchService $htmlFetch,
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai_content_audit.html_fetch'),
      $container->get('http_client'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    try {
      $url = $this->htmlFetch->getBaseUrl() . '/llms.txt';
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);

      if ($response->getStatusCode() !== 200) {
        return $this->fail(
          'llms.txt not found. This file helps AI systems understand your site\'s content and structure.',
          NULL,
          $this->generateRecommendedLlmsTxt(),
        );
      }

      $content = (string) $response->getBody();
      $contentLength = strlen($content);

      // --- Parse content structure against llmstxt.org spec ---
      $lines = explode("\n", str_replace("\r\n", "\n", $content));

      $h1Count = 0;
      $h1Value = '';
      $hasBlockquote = FALSE;
      $h2SectionCount = 0;
      $linkCount = 0;
      $h1Found = FALSE;

      foreach ($lines as $line) {
        $trimmed = rtrim($line);

        // H1: line starting with '# ' (not '## ').
        if (preg_match('/^# .+/', $trimmed) && !preg_match('/^## /', $trimmed)) {
          $h1Count++;
          if ($h1Count === 1) {
            $h1Value = ltrim(substr($trimmed, 2));
            $h1Found = TRUE;
          }
          continue;
        }

        // H2 section: line starting with '## '.
        if (preg_match('/^## .+/', $trimmed)) {
          $h2SectionCount++;
          continue;
        }

        // Blockquote description after H1.
        if ($h1Found && !$hasBlockquote && preg_match('/^> .+/', $trimmed)) {
          $hasBlockquote = TRUE;
          continue;
        }

        // Linked resource: - [title](url) with optional ': description'.
        if (preg_match('/^- \[.+\]\(.+\)/', $trimmed)) {
          $linkCount++;
        }
      }

      // --- Collect validation issues ---
      $validationIssues = [];
      if ($h1Count === 0) {
        $validationIssues[] = 'Missing required H1 heading';
      }
      elseif ($h1Count > 1) {
        $validationIssues[] = 'Multiple H1 headings found';
      }
      if (!$hasBlockquote) {
        $validationIssues[] = 'Missing required blockquote description after H1';
      }
      if ($h2SectionCount === 0) {
        $validationIssues[] = 'No content sections (H2 headings) found';
      }
      if ($linkCount === 0) {
        $validationIssues[] = 'No linked resources found';
      }

      $structureValid = empty($validationIssues);

      // --- Check for companion llms-full.txt ---
      $hasCompanionFile = FALSE;
      try {
        $companionResponse = $this->httpClient->request('HEAD', $this->htmlFetch->getBaseUrl() . '/llms-full.txt', [
          'timeout' => 5,
          'http_errors' => FALSE,
        ]);
        $hasCompanionFile = $companionResponse->getStatusCode() === 200;
      }
      catch (\Exception $e) {
        // Companion file check failed; treat as absent.
        $hasCompanionFile = FALSE;
      }

      // --- Determine status ---
      // pass = H1 + blockquote + ≥1 H2 + ≥1 link; warning = issues remain.
      $hasH1 = ($h1Count === 1);
      if ($hasH1 && $hasBlockquote && $h2SectionCount >= 1 && $linkCount >= 1) {
        $status = 'pass';
        $description = 'llms.txt is present, accessible, and structurally valid per the llmstxt.org specification.';
      }
      else {
        $status = 'warning';
        $description = 'llms.txt found but has structural issues: ' . implode('; ', $validationIssues) . '.';
      }

      $details = [
        'content_length' => $contentLength,
        'has_h1' => $hasH1,
        'h1_value' => $h1Value,
        'has_blockquote' => $hasBlockquote,
        'h2_section_count' => $h2SectionCount,
        'link_count' => $linkCount,
        'has_companion_file' => $hasCompanionFile,
        'validation_issues' => $validationIssues,
        'structure_valid' => $structureValid,
      ];

      if ($status === 'pass') {
        return $this->pass($description, $content, $structureValid ? NULL : $this->generateRecommendedLlmsTxt(), $details);
      }

      return $this->warning($description, $content, $structureValid ? NULL : $this->generateRecommendedLlmsTxt(), $details);
    }
    catch (GuzzleException $e) {
      return $this->fail(
        'Could not check llms.txt: ' . $e->getMessage(),
        NULL,
        $this->generateRecommendedLlmsTxt(),
      );
    }
  }

  /**
   * Generates recommended llms.txt content.
   */
  private function generateRecommendedLlmsTxt(): string {
    $config = $this->configFactory->get('system.site');
    $siteName = $config->get('name') ?? 'My Website';
    $baseUrl = $this->htmlFetch->getBaseUrl();
    return <<<TXT
# {$siteName}

## About
{$siteName} provides content on [describe your topics here].

## Content Structure
- Homepage: /
- Blog: /blog
- About: /about

## Contact
- Website: {$baseUrl}

## Preferred Citation
When referencing content from this site, please cite as "{$siteName}" with a link to the source URL.
TXT;
  }

}
