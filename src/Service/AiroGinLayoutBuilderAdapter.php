<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Optional adapter for Gin Layout Builder form behavior on AIRO routes.
 */
final class AiroGinLayoutBuilderAdapter {

  /**
   * Gin Layout Builder utility class.
   */
  private const UTILITY_CLASS = '\Drupal\gin_lb\GinLayoutBuilderUtility';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether the optional Gin Layout Builder integration can run.
   */
  public function applies(): bool {
    return $this->configFactory->get('system.theme')->get('admin') === 'gin'
      && class_exists(self::UTILITY_CLASS);
  }

  /**
   * Applies Gin Layout Builder form metadata and libraries.
   */
  public function attachForm(array &$form): void {
    if (!$this->applies()) {
      return;
    }

    $form['#gin_lb_form'] = TRUE;
    $form['#attributes']['class'][] = 'glb-form';
    foreach ($this->getLibraries() as $library) {
      $form['#attached']['library'][] = $library;
    }

    $utilityClass = self::UTILITY_CLASS;
    $utilityClass::attachGinLbForm($form);
  }

  /**
   * Returns Gin Layout Builder libraries for the optional integration path.
   */
  private function getLibraries(): array {
    return [
      'gin_lb/gin_lb',
      'gin_lb/gin_lb_10',
      'gin_lb/gin_lb_init',
      'gin_lb/offcanvas',
      'gin_lb/preview',
      'gin_lb/toolbar',
      'gin/gin_ckeditor',
      'claro/claro.jquery.ui',
      'claro/global-styling',
    ];
  }

}
