<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\Plugin\media\Source\AssetKiwiAsset;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for asset.kiwi integration.
 */
class AssetKiwiCommands extends DrushCommands {

  /**
   * Constructs an AssetKiwiCommands instance.
   */
  public function __construct(
    protected AssetKiwiClient $assetKiwiClient,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('assetkiwi_connect.client'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Import assets from asset.kiwi.into Drupal media entities.
   */
  #[CLI\Command(name: 'assetkiwi:import-assets')]
  #[CLI\Argument(name: 'bundle', description: 'The media type machine name (e.g. assetkiwi_asset)')]
  #[CLI\Option(name: 'collection', description: 'Import from a specific collection slug')]
  #[CLI\Option(name: 'tag', description: 'Import assets with a specific tag slug')]
  #[CLI\Option(name: 'type', description: 'Filter by asset type (image|video|audio|document)')]
  #[CLI\Option(name: 'search', description: 'Search query to filter assets')]
  #[CLI\Option(name: 'limit', description: 'Maximum number of assets to import (0 = unlimited)')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would be imported without actually importing')]
  #[CLI\Option(name: 'uuid', description: 'Import a single asset by UUID')]
  #[CLI\Usage(name: 'drush assetkiwi:import-assets assetkiwi_asset --collection=marketing-images', description: 'Import all assets from the "marketing-images" collection')]
  #[CLI\Usage(name: 'drush assetkiwi:import-assets assetkiwi_asset --tag=hero --limit=10', description: 'Import up to 10 assets tagged "hero"')]
  #[CLI\Usage(name: 'drush assetkiwi:import-assets assetkiwi_asset --uuid=abc-123-def --dry-run', description: 'Preview what importing a specific UUID would do')]
  public function importAssets(
    string $bundle,
    array $options = [
      'collection' => '',
      'tag' => '',
      'type' => '',
      'search' => '',
      'limit' => 0,
      'dry-run' => FALSE,
      'uuid' => '',
    ],
  ): void {
    // Load the media type to verify it exists and uses the AssetKiwiAsset source.
    $mediaType = $this->entityTypeManager->getStorage('media_type')->load($bundle);
    if (!$mediaType) {
      $this->logger()->error(dt('Media type "@bundle" not found.', ['@bundle' => $bundle]));
      return;
    }

    $source = $mediaType->getSource();
    if (!$source instanceof asset.kiwiAsset) {
      $this->logger()->error(dt('Media type "@bundle" must use the asset.kiwi Asset source plugin.', ['@bundle' => $bundle]));
      return;
    }

    $sourceFieldName = $source->getSourceFieldName();

    // Determine which assets to import.
    $uuids = [];

    if (!empty($options['uuid'])) {
      // Single UUID import.
      $uuids = [$options['uuid']];
    }
    else {
      // Fetch assets from the API with filters.
      $params = [
        'per_page' => min(100, $options['limit'] > 0 ? $options['limit'] : 100),
      ];

      if (!empty($options['collection'])) {
        $params['collection'] = $options['collection'];
      }
      if (!empty($options['tag'])) {
        $params['tag'] = $options['tag'];
      }
      if (!empty($options['type'])) {
        $params['type'] = $options['type'];
      }
      if (!empty($options['search'])) {
        $params['search'] = $options['search'];
      }

      $response = $this->assetKiwiClient->getAssets($params);
      $items = $response['data'] ?? $response;

      if (empty($items)) {
        $this->logger()->warning(dt('No assets found matching the criteria.'));
        if ($error = $this->assetKiwiClient->getLastError()) {
          $this->logger()->error(dt('API error: @error', ['@error' => $error]));
        }
        return;
      }

      foreach ($items as $asset) {
        $uuids[] = $asset['uuid'] ?? $asset['id'] ?? '';
      }
      $uuids = array_filter($uuids);

      // Handle pagination for larger imports.
      if ($options['limit'] === 0 || count($uuids) < $options['limit']) {
        $pager = $this->assetKiwiClient->normalizePager($response, 1);
        $lastPage = $pager['last_page'] ?? 1;

        for ($page = 2; $page <= $lastPage; $page++) {
          if ($options['limit'] > 0 && count($uuids) >= $options['limit']) {
            break;
          }

          $params['page'] = $page;
          $nextResponse = $this->assetKiwiClient->getAssets($params);
          $nextItems = $nextResponse['data'] ?? $nextResponse;

          foreach ($nextItems as $asset) {
            $uuid = $asset['uuid'] ?? $asset['id'] ?? '';
            if (!empty($uuid)) {
              $uuids[] = $uuid;
              if ($options['limit'] > 0 && count($uuids) >= $options['limit']) {
                break;
              }
            }
          }
        }
      }

      if ($options['limit'] > 0) {
        $uuids = array_slice($uuids, 0, $options['limit']);
      }
    }

    if (empty($uuids)) {
      $this->logger()->warning(dt('No valid UUIDs found.'));
      return;
    }

    $this->logger()->info(dt('Found @count asset(s) to import.', ['@count' => count($uuids)]));

    if ($options['dry-run']) {
      $this->io()->table(
        ['UUID', 'Would create media entity in "' . $bundle . '"'],
        array_map(fn($uuid) => [$uuid, 'Yes'], $uuids)
      );
      $this->logger()->success(dt('Dry run complete. @count asset(s) would be imported.', ['@count' => count($uuids)]));
      return;
    }

    // Perform the import.
    $imported = 0;
    $skipped = 0;
    $errors = 0;

    $mediaStorage = $this->entityTypeManager->getStorage('media');

    $this->io()->progressStart(count($uuids));

    foreach ($uuids as $uuid) {
      try {
        // Check if this UUID is already imported.
        $existing = $mediaStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition($sourceFieldName, $uuid)
          ->range(0, 1)
          ->execute();

        if (!empty($existing)) {
          $skipped++;
          $this->io()->progressAdvance();
          continue;
        }

        // Fetch asset metadata.
        $asset = $this->assetKiwiClient->getAsset($uuid);
        if (empty($asset)) {
          $errors++;
          $this->io()->progressAdvance();
          continue;
        }

        $data = $this->assetKiwiClient->normalizeAsset($asset);
        $displayName = $this->assetKiwiClient->getAssetDisplayName($data);

        // Create the media entity.
        $media = $mediaStorage->create([
          'bundle' => $bundle,
          $sourceFieldName => $uuid,
          'name' => $displayName !== 'Untitled' ? $displayName : $uuid,
        ]);
        $media->save();
        $imported++;

        // Optionally download the file for image type media.
        if (str_starts_with($data['mime_type'] ?? $data['mimeType'] ?? '', 'image/')) {
          try {
            $fileInfo = $this->assetKiwiClient->downloadAssetFile($uuid);
            if ($fileInfo !== NULL) {
              // Try to set on common image/file fields.
              $media->set('field_media_image', [
                'target_id' => $fileInfo['fid'],
                'alt' => $data['alt_text'] ?? $data['alt'] ?? $displayName,
              ])->save();
            }
          }
          catch (\Exception $e) {
            // File download is optional; don't fail the import.
            $this->logger()->warning(dt('Could not download file for @uuid: @message', [
              '@uuid' => $uuid,
              '@message' => $e->getMessage(),
            ]));
          }
        }
      }
      catch (\Exception $e) {
        $this->logger()->error(dt('Failed to import @uuid: @message', [
          '@uuid' => $uuid,
          '@message' => $e->getMessage(),
        ]));
        $errors++;
      }

      $this->io()->progressAdvance();
    }

    $this->io()->progressFinish();

    $this->logger()->success(dt('Import complete: @imported imported, @skipped skipped, @errors errors.', [
      '@imported' => $imported,
      '@skipped' => $skipped,
      '@errors' => $errors,
    ]));
  }

}
