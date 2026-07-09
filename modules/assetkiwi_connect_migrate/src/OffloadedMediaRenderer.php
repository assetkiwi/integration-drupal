<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect_migrate;

use Drupal\Core\Url;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\Plugin\media\Source\AssetKiwiAsset;
use Drupal\assetkiwi_connect\Utility\MimeIconResolver;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Implements the view-layer swap (Option B) for offloaded/purged media.
 *
 * The local file's field value and file entity are never touched (see
 * MigrationManager) — this class only changes what gets rendered, by
 * replacing the source field's built render array with one pointing at the
 * asset.kiwi copy, the same way AssetKiwiImageFormatter/AssetKiwiLinkFormatter
 * already render natively-added asset.kiwi media. On any failure to resolve
 * the remote asset, this silently leaves the original render array alone —
 * for an offloaded (not yet purged) item that just means it renders locally,
 * which is safe; for a purged item it means the local file is gone and
 * nothing can be shown, which is logged loudly since it indicates a genuine
 * problem (the remote asset should always exist once purged).
 */
class OffloadedMediaRenderer {

  public function __construct(
    protected MigrationStatusStorage $statusStorage,
    protected AssetKiwiClient $assetKiwiClient,
    protected MimeIconResolver $mimeIconResolver,
    protected LoggerInterface $logger,
  ) {}

  public function alter(array &$build, MediaInterface $media): void {
    $status = $this->statusStorage->getStatus('media', (int) $media->id());
    if ($status === NULL || !in_array($status['status'], [MigrationStatusStorage::STATUS_OFFLOADED, MigrationStatusStorage::STATUS_PURGED], TRUE)) {
      return;
    }

    $uuid = $status['assetkiwi_uuid'] ?? NULL;
    if (empty($uuid)) {
      return;
    }

    $mediaType = $media->bundle->entity;
    $source = $mediaType?->getSource();
    if ($mediaType === NULL || $source === NULL || $source instanceof AssetKiwiAsset) {
      // Already natively asset.kiwi-sourced — nothing for this hook to swap.
      return;
    }
    $fieldDefinition = $source->getSourceFieldDefinition($mediaType);
    if ($fieldDefinition === NULL || !isset($build[$fieldDefinition->getName()])) {
      return;
    }
    $fieldName = $fieldDefinition->getName();

    $asset = $this->assetKiwiClient->getAsset($uuid);
    if (empty($asset)) {
      if ($status['status'] === MigrationStatusStorage::STATUS_PURGED) {
        $this->logger->error('Purged media @id has no local file and its remote asset @uuid could not be resolved from asset.kiwi — nothing can be rendered for it.', [
          '@id' => $media->id(),
          '@uuid' => $uuid,
        ]);
      }
      return;
    }
    $data = $this->assetKiwiClient->normalizeAsset($asset);

    $weight = $build[$fieldName]['#weight'] ?? NULL;
    $build[$fieldName] = $this->buildRenderArray($data) + [
      '#cache' => ['tags' => ['media:' . $media->id()]],
    ];
    if ($weight !== NULL) {
      $build[$fieldName]['#weight'] = $weight;
    }
  }

  private function buildRenderArray(array $data): array {
    $mime = $data['mime_type'] ?? $data['mimeType'] ?? '';
    $url = $data['url'] ?? $data['download_url'] ?? NULL;

    if ($url && str_starts_with($mime, 'image/')) {
      return [
        '#theme' => 'image',
        '#uri' => $url,
        '#alt' => $data['alt_text'] ?? $data['original_name'] ?? '',
        '#attributes' => [
          'loading' => 'lazy',
          'class' => ['assetkiwi-image', 'assetkiwi-offloaded'],
        ],
      ];
    }

    if (!$url) {
      return [];
    }

    $filename = $data['original_name'] ?? $data['name'] ?? $data['filename'] ?? 'Download';
    $bucket = $this->mimeIconResolver->getIconBucket($mime);
    $core_class = $this->mimeIconResolver->getCoreIconClass($mime);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['assetkiwi-file-link', 'assetkiwi-offloaded']],
      '#attached' => ['library' => ['assetkiwi_connect/file_icons']],
      'icon' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'class' => ['assetkiwi-file-icon', 'assetkiwi-file-icon--' . $bucket, 'file--' . $core_class],
          'aria-hidden' => 'true',
        ],
      ],
      'link' => [
        '#type' => 'link',
        '#title' => $filename,
        '#url' => Url::fromUri($url),
        '#attributes' => ['target' => '_blank', 'rel' => 'noopener'],
      ],
    ];
  }

}
