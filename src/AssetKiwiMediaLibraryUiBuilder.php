<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect;

use Drupal\assetkiwi_connect\Plugin\media\Source\AssetKiwiAsset;
use Drupal\media_library\MediaLibraryState;
use Drupal\media_library\MediaLibraryUiBuilder;

/** Suppresses core's "existing media" grid for asset.kiwi media types — the picker is the source of truth. */
class AssetKiwiMediaLibraryUiBuilder extends MediaLibraryUiBuilder {

  /**
   * {@inheritdoc}
   */
  protected function buildLibraryContent(MediaLibraryState $state) {
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($state->getSelectedTypeId());

    if ($media_type && $media_type->getSource() instanceof AssetKiwiAsset) {
      return [
        '#type' => 'container',
        '#theme_wrappers' => ['container__media_library_content'],
        '#attributes' => ['id' => 'media-library-content'],
        'form' => $this->buildMediaTypeAddForm($state),
      ];
    }

    return parent::buildLibraryContent($state);
  }

}
