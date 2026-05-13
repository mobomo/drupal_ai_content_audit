<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\layout_builder\Traits\EnableLayoutBuilderTrait;
use Drupal\user\Entity\User;

/**
 * Kernel tests for Layout Builder extraction used by the HTML extractor.
 *
 * @group ai_content_audit
 */
final class LayoutBuilderPreviewSourceKernelTest extends KernelTestBase {

  use EnableLayoutBuilderTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'block',
    'contextual',
    'layout_discovery',
    'layout_builder',
    'ai_content_audit',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node']);

    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();

    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Body',
    ])->save();

    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'article',
      'mode' => 'full',
      'status' => TRUE,
    ]);
    $display->setComponent('body', [
      'type' => 'text_default',
      'weight' => 0,
    ]);
    $display->save();

    $display = LayoutBuilderEntityViewDisplay::load('node.article.full');
    $this->assertNotNull($display);
    $this->enableLayoutBuilder($display);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Ensures LB sections resolve and render expected block output for a revision.
   */
  public function testBuildSectionsRenderArrayIsNonEmptyWithOverride(): void {
    $user = User::create(['name' => 'lb_kernel_user']);
    $user->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Layout Builder kernel node',
      'uid' => $user->id(),
      'body' => [
        [
          'value' => 'UniqueBodyMarkerXyz',
          'format' => 'plain_text',
        ],
      ],
    ]);
    $node->save();

    $uuid = $this->container->get('uuid')->generate();
    $section = new Section('layout_onecol', [], [
      new SectionComponent($uuid, 'content', [
        'id' => 'system_powered_by_block',
        'label' => 'Powered',
        'label_display' => '0',
        'provider' => 'system',
      ]),
    ]);
    $node->get('layout_builder__layout')->appendSection($section);
    $node->save();

    /** @var \Drupal\ai_content_audit\Service\LayoutBuilderPreviewSource $source */
    $source = $this->container->get('ai_content_audit.layout_builder_preview_source');
    $sections = $source->getSectionsForNode($node, 'full');
    $this->assertNotEmpty($sections, 'Node with LB override must expose sections.');

    $build = $source->buildSectionsRenderArray($node, 'full');
    $this->assertNotEmpty($build, 'LB render build must be non-empty.');

    $html = (string) $this->container->get('renderer')->renderRoot([
      '#type' => 'container',
      'lb' => $build,
    ]);
    $this->assertStringContainsString('Drupal', $html, 'Rendered layout should include the system branding block text.');
  }

}
