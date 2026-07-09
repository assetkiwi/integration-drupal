<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect_migrate;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\Plugin\media\Source\AssetKiwiAsset;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Migrates locally-stored Drupal media to asset.kiwi.
 *
 * Pipeline per item (see MIGRATION_PLAN.md): upload -> verify -> offload.
 * Nothing in this pipeline ever deletes or mutates the local file or the
 * underlying media entity's field values — "offloaded" is purely a tracking
 * table status (Option B / view-layer swap: rendering is meant to prefer the
 * asset.kiwi copy when this status is set, without touching the stored
 * field). Only ::purgeItem(), a distinct and separately-invoked operation,
 * ever deletes a local file, and only after re-verifying the remote copy.
 *
 * The Drupal-entity-heavy glue (::resolveLocalFile, ::scanCandidates) is kept
 * separate from the pure pipeline logic (::processLocalFile) so the pipeline
 * — the part most likely to have a bug — can be unit tested directly with a
 * mocked client and a plain array describing the local file, without needing
 * a live Drupal bootstrap.
 */
class MigrationManager {

  public function __construct(
    protected AssetKiwiClient $assetKiwiClient,
    protected MigrationStatusStorage $statusStorage,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Finds local (non-asset.kiwi-sourced, file-backed) media eligible to migrate.
   *
   * "Eligible" means: the media type's source field is a real File/Image
   * field (so there's an actual local file to upload — media types like
   * remote video/oEmbed have nothing local and are never candidates), the
   * source is not already assetkiwi_asset, and a local file genuinely exists
   * on disk.
   *
   * @param string[] $bundles
   *   Media type machine names to restrict to; empty means all eligible types.
   * @param int $limit
   *   Maximum candidates to return; 0 means unlimited.
   *
   * @return array<int, array{media_id: int, bundle: string, label: string, size: int, status: string}>
   *   status is one of: 'eligible', 'already_offloaded', 'already_purged'.
   */
  public function scanCandidates(array $bundles = [], int $limit = 0): array {
    $eligibleBundles = $this->resolveEligibleBundles($bundles);
    if (empty($eligibleBundles)) {
      return [];
    }

    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $query = $mediaStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', array_keys($eligibleBundles), 'IN')
      ->sort('mid', 'ASC');
    if ($limit > 0) {
      $query->range(0, $limit);
    }
    $mediaIds = $query->execute();
    if (empty($mediaIds)) {
      return [];
    }

    $statuses = $this->statusStorage->getStatuses('media', array_map('intval', $mediaIds));

    $candidates = [];
    foreach ($mediaStorage->loadMultiple($mediaIds) as $media) {
      $mid = (int) $media->id();
      $existingStatus = $statuses[$mid]['status'] ?? NULL;

      if ($existingStatus === MigrationStatusStorage::STATUS_OFFLOADED) {
        $candidates[] = ['media_id' => $mid, 'bundle' => $media->bundle(), 'label' => $media->label(), 'size' => 0, 'status' => 'already_offloaded'];
        continue;
      }
      if ($existingStatus === MigrationStatusStorage::STATUS_PURGED) {
        $candidates[] = ['media_id' => $mid, 'bundle' => $media->bundle(), 'label' => $media->label(), 'size' => 0, 'status' => 'already_purged'];
        continue;
      }

      $fieldName = $eligibleBundles[$media->bundle()];
      $file = $this->resolveLocalFile($media, $fieldName);
      if ($file === NULL) {
        // No local file (empty field, or the file record/disk file is gone).
        continue;
      }

      $candidates[] = [
        'media_id' => $mid,
        'bundle' => $media->bundle(),
        'label' => $media->label(),
        'size' => $file->getSize(),
        'status' => 'eligible',
      ];
    }

    return $candidates;
  }

  /**
   * Resolves which media types are local (file-backed, non-asset.kiwi).
   *
   * Factored out of ::scanCandidates() so the UI can populate a bundle
   * checklist (::getEligibleBundleOptions()) without duplicating the
   * eligibility rule.
   *
   * @return array<string, string>
   *   Media type id => source field name.
   */
  protected function resolveEligibleBundles(array $bundles = []): array {
    $eligibleBundles = [];
    foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $mediaType) {
      $id = $mediaType->id();
      if (!empty($bundles) && !in_array($id, $bundles, TRUE)) {
        continue;
      }
      $source = $mediaType->getSource();
      if ($source instanceof AssetKiwiAsset) {
        // Already asset.kiwi-sourced — nothing local to migrate.
        continue;
      }
      $fieldDefinition = $source->getSourceFieldDefinition($mediaType);
      if ($fieldDefinition === NULL || !in_array($fieldDefinition->getType(), ['file', 'image'], TRUE)) {
        // No real local file backs this source (e.g. remote video/oEmbed).
        continue;
      }
      $eligibleBundles[$id] = $fieldDefinition->getName();
    }
    return $eligibleBundles;
  }

  /**
   * Returns media type id => label for all locally-migratable bundles.
   *
   * For populating a bundle checklist in the migration UI.
   *
   * @return array<string, string>
   */
  public function getEligibleBundleOptions(): array {
    $options = [];
    $mediaTypeStorage = $this->entityTypeManager->getStorage('media_type');
    foreach (array_keys($this->resolveEligibleBundles()) as $id) {
      $mediaType = $mediaTypeStorage->load($id);
      $options[$id] = $mediaType ? $mediaType->label() : $id;
    }
    return $options;
  }

  /**
   * Resolves the local File entity backing a media entity's source field.
   *
   * Returns NULL when the field is empty, the referenced file entity is
   * gone, or the file no longer exists on disk — any of which means there is
   * nothing local to migrate for this item.
   */
  protected function resolveLocalFile(MediaInterface $media, string $fieldName): ?FileInterface {
    if (!$media->hasField($fieldName) || $media->get($fieldName)->isEmpty()) {
      return NULL;
    }
    $fileId = $media->get($fieldName)->target_id ?? NULL;
    if (!$fileId) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fileId);
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $realpath = $this->fileSystem->realpath($file->getFileUri());
    if ($realpath === FALSE || !is_readable($realpath)) {
      return NULL;
    }
    return $file;
  }

  /**
   * Runs the migration pipeline for one media entity.
   *
   * @param int $mediaId
   * @param bool $dryRun
   *   When TRUE, never uploads — only reports what would happen.
   *
   * @return array{status: string, uuid: ?string, reused: ?bool, error: ?string}
   */
  public function migrateItem(int $mediaId, bool $dryRun = FALSE): array {
    $existing = $this->statusStorage->getStatus('media', $mediaId);
    if ($existing !== NULL && in_array($existing['status'], [MigrationStatusStorage::STATUS_OFFLOADED, MigrationStatusStorage::STATUS_PURGED], TRUE)) {
      // Idempotent: already dealt with, nothing to do.
      return ['status' => $existing['status'], 'uuid' => $existing['assetkiwi_uuid'], 'reused' => NULL, 'error' => NULL, 'skipped' => TRUE];
    }

    $media = $this->entityTypeManager->getStorage('media')->load($mediaId);
    if ($media === NULL) {
      return $this->fail('media', $mediaId, NULL, 'Media entity no longer exists.');
    }

    $mediaType = $media->bundle->entity;
    $source = $mediaType?->getSource();
    if ($mediaType === NULL || $source === NULL || $source instanceof AssetKiwiAsset) {
      return $this->fail('media', $mediaId, NULL, 'Not a local (file-backed) media type.');
    }
    $fieldDefinition = $source->getSourceFieldDefinition($mediaType);
    if ($fieldDefinition === NULL || !in_array($fieldDefinition->getType(), ['file', 'image'], TRUE)) {
      return $this->fail('media', $mediaId, NULL, 'Media type has no local file source field.');
    }

    $file = $this->resolveLocalFile($media, $fieldDefinition->getName());
    if ($file === NULL) {
      return $this->fail('media', $mediaId, NULL, 'No readable local file found for this media item.');
    }

    $realpath = $this->fileSystem->realpath($file->getFileUri());
    $localFile = [
      'realpath' => $realpath,
      'uri' => $file->getFileUri(),
      'filename' => $file->getFilename(),
      'mime' => $file->getMimeType(),
      'size' => (int) $file->getSize(),
    ];

    return $this->processLocalFile('media', $mediaId, $localFile, $dryRun);
  }

  /**
   * The pure pipeline: hash -> upload -> verify -> offload.
   *
   * Deliberately takes a plain array describing the local file rather than a
   * MediaInterface, so it can be exercised in a unit test with a mocked
   * AssetKiwiClient and no Drupal bootstrap.
   *
   * @param array{realpath: string, uri: string, filename: string, mime: string, size: int} $localFile
   */
  public function processLocalFile(string $entityType, int $entityId, array $localFile, bool $dryRun = FALSE): array {
    $hash = is_readable($localFile['realpath']) ? hash_file('sha256', $localFile['realpath']) : NULL;

    if ($dryRun) {
      // Honest limitation (see MIGRATION_PLAN.md §7 Q2): asset.kiwi has no
      // hash-lookup-only endpoint, so a dry run can report "would upload"
      // but cannot predict whether the real run would hit the 409 dedup
      // path and reuse an existing asset instead.
      return [
        'status' => 'would_upload',
        'uuid' => NULL,
        'reused' => NULL,
        'error' => NULL,
        'skipped' => FALSE,
      ];
    }

    $this->statusStorage->upsert($entityType, $entityId, [
      'status' => MigrationStatusStorage::STATUS_UPLOADING,
      'file_hash' => $hash,
      'local_uri' => $localFile['uri'],
      'attempted_at' => time(),
    ]);

    $upload = $this->assetKiwiClient->uploadAsset($localFile['realpath'], $localFile['filename'], $localFile['mime']);
    if ($upload === NULL) {
      return $this->fail($entityType, $entityId, $hash, $this->assetKiwiClient->getLastError() ?? 'Upload failed for an unknown reason.');
    }

    $uuid = $upload['uuid'];
    $this->statusStorage->upsert($entityType, $entityId, [
      'status' => MigrationStatusStorage::STATUS_UPLOADED,
      'assetkiwi_uuid' => $uuid,
    ]);

    $remote = $this->assetKiwiClient->getAsset($uuid);
    if (empty($remote)) {
      return $this->fail($entityType, $entityId, $hash, "Verification failed: asset {$uuid} not found after upload.", $uuid);
    }
    $remoteData = $this->assetKiwiClient->normalizeAsset($remote);
    $remoteSize = $remoteData['size'] ?? $remoteData['file_size'] ?? NULL;
    if ($remoteSize === NULL || (int) $remoteSize !== $localFile['size']) {
      return $this->fail(
        $entityType,
        $entityId,
        $hash,
        "Verification failed: remote size ({$remoteSize}) does not match local size ({$localFile['size']}) for asset {$uuid}.",
        $uuid,
      );
    }

    $this->statusStorage->upsert($entityType, $entityId, ['status' => MigrationStatusStorage::STATUS_VERIFIED]);

    // Offload = tracking-only status flip (Option B). The local file and the
    // media entity's field value are never touched here.
    $this->statusStorage->upsert($entityType, $entityId, [
      'status' => MigrationStatusStorage::STATUS_OFFLOADED,
      'completed_at' => time(),
    ]);

    return [
      'status' => MigrationStatusStorage::STATUS_OFFLOADED,
      'uuid' => $uuid,
      'reused' => $upload['reused'],
      'error' => NULL,
      'skipped' => FALSE,
    ];
  }

  /**
   * Re-verifies a remote asset still exists, then deletes only the local file.
   *
   * Never deletes the media entity or the file entity record — only the
   * physical file on disk. Refuses to run on anything not already tracked as
   * 'offloaded'. If the remote asset can't be confirmed, the local file is
   * left completely untouched and the item is marked failed so the discrepancy
   * is visible rather than silently retried into data loss.
   */
  public function purgeItem(int $mediaId): array {
    $existing = $this->statusStorage->getStatus('media', $mediaId);
    if ($existing === NULL || $existing['status'] === MigrationStatusStorage::STATUS_PURGED) {
      return ['status' => $existing['status'] ?? 'not_tracked', 'error' => NULL, 'skipped' => TRUE];
    }
    if ($existing['status'] !== MigrationStatusStorage::STATUS_OFFLOADED) {
      return ['status' => $existing['status'], 'error' => 'Refusing to purge: item is not in the offloaded state.', 'skipped' => TRUE];
    }

    $uuid = $existing['assetkiwi_uuid'];
    $remote = $uuid ? $this->assetKiwiClient->getAsset($uuid) : [];
    if (empty($remote)) {
      $this->statusStorage->upsert('media', $mediaId, [
        'status' => MigrationStatusStorage::STATUS_FAILED,
        'error_message' => "Purge aborted: could not re-verify asset {$uuid} still exists on asset.kiwi. Local file left untouched.",
        'attempted_at' => time(),
      ]);
      return ['status' => MigrationStatusStorage::STATUS_FAILED, 'error' => 'Remote asset could not be re-verified; local file was not touched.', 'skipped' => FALSE];
    }

    $media = $this->entityTypeManager->getStorage('media')->load($mediaId);
    $localUri = $existing['local_uri'];
    if ($media !== NULL) {
      $mediaType = $media->bundle->entity;
      $source = $mediaType?->getSource();
      $fieldDefinition = $source?->getSourceFieldDefinition($mediaType);
      if ($fieldDefinition !== NULL) {
        $file = $this->resolveLocalFile($media, $fieldDefinition->getName());
        if ($file !== NULL) {
          $localUri = $file->getFileUri();
        }
      }
    }

    $realpath = $localUri ? $this->fileSystem->realpath($localUri) : FALSE;
    if ($realpath !== FALSE && file_exists($realpath)) {
      if (!@unlink($realpath)) {
        $this->logger->warning('Could not delete local file @path for media @id during purge.', ['@path' => $realpath, '@id' => $mediaId]);
        return ['status' => MigrationStatusStorage::STATUS_OFFLOADED, 'error' => 'Could not delete the local file (permissions?). Item left as offloaded, not purged.', 'skipped' => FALSE];
      }
    }

    $this->statusStorage->upsert('media', $mediaId, [
      'status' => MigrationStatusStorage::STATUS_PURGED,
      'completed_at' => time(),
    ]);

    return ['status' => MigrationStatusStorage::STATUS_PURGED, 'error' => NULL, 'skipped' => FALSE];
  }

  /**
   * Returns per-status counts for the migration dashboard/status command.
   */
  public function getStatusCounts(): array {
    return $this->statusStorage->countByStatus('media');
  }

  private function fail(string $entityType, int $entityId, ?string $hash, string $message, ?string $uuid = NULL): array {
    $fields = [
      'status' => MigrationStatusStorage::STATUS_FAILED,
      'error_message' => $message,
      'attempted_at' => time(),
    ];
    if ($hash !== NULL) {
      $fields['file_hash'] = $hash;
    }
    if ($uuid !== NULL) {
      $fields['assetkiwi_uuid'] = $uuid;
    }
    $this->statusStorage->upsert($entityType, $entityId, $fields);

    $this->logger->error('asset.kiwi migration failed for @type @id: @message', [
      '@type' => $entityType,
      '@id' => $entityId,
      '@message' => $message,
    ]);

    return ['status' => MigrationStatusStorage::STATUS_FAILED, 'uuid' => $uuid, 'reused' => NULL, 'error' => $message, 'skipped' => FALSE];
  }

}
