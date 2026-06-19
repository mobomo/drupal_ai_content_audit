<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\Service;

use Drupal\ai_content_audit\Service\NodeEditFormAlterer;
use Drupal\Core\Form\FormStateInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests AIRO node edit form cleanup helpers.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit\Service\NodeEditFormAlterer
 */
final class NodeEditFormAltererTest extends TestCase {

  /**
   * Verifies after-build cleanup removes sidebar artifacts on AIRO routes.
   *
   * @covers ::afterBuildStripSidebarPanel
   * @covers ::stripAiroAnalysisTabSidebar
   */
  public function testAfterBuildStripSidebarPanelRemovesAiroSidebarArtifacts(): void {
    $form = [
      '#attributes' => [
        'class' => ['node-form', 'airo-analysis-page__edit-form'],
      ],
      'advanced' => [
        '#type' => 'vertical_tabs',
      ],
      'airo_analysis' => [
        '#type' => 'details',
      ],
      'nested' => [
        'panel' => [
          '#theme' => 'ai_airo_accordion_item',
        ],
      ],
    ];

    $result = NodeEditFormAlterer::afterBuildStripSidebarPanel(
      $form,
      $this->createMock(FormStateInterface::class)
    );

    $this->assertArrayNotHasKey('airo_analysis', $result);
    $this->assertFalse($result['advanced']['#access']);
    $this->assertArrayNotHasKey('nested', $result);
  }

  /**
   * Verifies after-build cleanup is scoped to AIRO Analysis edit forms.
   *
   * @covers ::afterBuildStripSidebarPanel
   */
  public function testAfterBuildStripSidebarPanelIgnoresOtherForms(): void {
    $form = [
      '#attributes' => [
        'class' => ['node-form'],
      ],
      'advanced' => [
        '#type' => 'vertical_tabs',
      ],
      'airo_analysis' => [
        '#type' => 'details',
      ],
    ];

    $result = NodeEditFormAlterer::afterBuildStripSidebarPanel(
      $form,
      $this->createMock(FormStateInterface::class)
    );

    $this->assertArrayHasKey('airo_analysis', $result);
    $this->assertArrayNotHasKey('#access', $result['advanced']);
  }

}
