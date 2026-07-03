<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\Service;

use Drupal\ai_content_audit\Service\AiroGinLayoutBuilderAdapter;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the optional Gin Layout Builder adapter.
 */
#[Group('ai_content_audit')]
#[CoversClass(AiroGinLayoutBuilderAdapter::class)]
final class AiroGinLayoutBuilderAdapterTest extends TestCase {

  /**
   * Verifies the adapter is disabled when Gin is not the admin theme.
   */
  public function testAdapterDoesNotApplyWithoutGinAdminTheme(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('admin')->willReturn('claro');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('system.theme')->willReturn($config);

    $adapter = new AiroGinLayoutBuilderAdapter($configFactory);
    $form = [];

    $this->assertFalse($adapter->applies());
    $adapter->attachForm($form);
    $this->assertSame([], $form);
  }

}
