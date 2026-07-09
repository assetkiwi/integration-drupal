<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect_migrate\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\assetkiwi_connect_migrate\OffloadedMediaRenderer;
use Drupal\media\MediaInterface;

/**
 * Hook implementations for the assetkiwi_connect_migrate module.
 */
class AssetkiwiConnectMigrateHooks {

  public function __construct(
    protected OffloadedMediaRenderer $renderSwapper,
  ) {}

  /**
   * Implements hook_media_view_alter().
   *
   * Swaps the rendered source field for offloaded/purged media to the
   * asset.kiwi copy (Option B / view-layer swap — see MIGRATION_PLAN.md §4.5
   * and OffloadedMediaRenderer). The local file and field value are never
   * touched; only what gets rendered changes.
   */
  #[Hook('media_view_alter')]
  public function mediaViewAlter(array &$build, MediaInterface $media, EntityViewDisplayInterface $display): void {
    $this->renderSwapper->alter($build, $media);
  }

}
