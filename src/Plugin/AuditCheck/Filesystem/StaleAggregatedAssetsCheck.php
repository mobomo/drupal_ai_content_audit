<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\AuditCheck\Filesystem;

use Drupal\ai_content_audit\Attribute\AuditCheck;
use Drupal\ai_content_audit\ValueObject\TechnicalAuditResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Counts CSS and JS aggregation files; warns when total exceeds 500.
 */
#[AuditCheck(
  id: 'fs_stale_assets',
  label: new TranslatableMarkup('Stale Aggregated Assets'),
  description: new TranslatableMarkup('Counts CSS and JS aggregation files; warns when total exceeds 500.'),
  scope: 'site',
  category: 'Filesystem Health',
)]
class StaleAggregatedAssetsCheck extends FilesystemCheckBase {

  /**
   * {@inheritdoc}
   */
  public function run(?NodeInterface $node = NULL): TechnicalAuditResult {
    $cssCount = 0;
    $jsCount = 0;

    $cssDir = $this->safePath('sites/default/files/css');
    if ($cssDir !== NULL && is_dir($cssDir)) {
      $cssCount = iterator_count(
        new \FilesystemIterator($cssDir, \FilesystemIterator::SKIP_DOTS)
      );
    }

    $jsDir = $this->safePath('sites/default/files/js');
    if ($jsDir !== NULL && is_dir($jsDir)) {
      $jsCount = iterator_count(
        new \FilesystemIterator($jsDir, \FilesystemIterator::SKIP_DOTS)
      );
    }

    $total = $cssCount + $jsCount;
    $details = ['css_file_count' => $cssCount, 'js_file_count' => $jsCount];

    if ($cssCount < 100 && $jsCount < 100) {
      return $this->pass(
        'Aggregated CSS and JS file counts are within normal range.',
        sprintf('CSS: %d, JS: %d', $cssCount, $jsCount),
        'Under 100 files each',
        $details,
      );
    }

    if ($total >= 500) {
      return $this->warning(
        sprintf('%d aggregated asset files detected. A large number of stale assets wastes disk space; run "drush cr" or clear the asset cache.', $total),
        sprintf('CSS: %d, JS: %d (total: %d)', $cssCount, $jsCount, $total),
        'Under 100 files each',
        $details,
      );
    }

    return $this->info(
      sprintf('%d aggregated asset files detected. Consider clearing aggregated assets periodically.', $total),
      sprintf('CSS: %d, JS: %d (total: %d)', $cssCount, $jsCount, $total),
      'Under 100 files each',
      $details,
    );
  }

}
