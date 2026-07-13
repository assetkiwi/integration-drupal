<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/** Swaps in AssetKiwiMediaLibraryUiBuilder for core's media_library.ui_builder service. */
class AssetkiwiConnectServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('media_library.ui_builder')) {
      $container->getDefinition('media_library.ui_builder')
        ->setClass(AssetKiwiMediaLibraryUiBuilder::class);
    }
  }

}
