<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Plugin\media\Source;

use Drupal\Core\Form\FormStateInterface;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\Utility\MimeIconResolver;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a media source plugin for asset.kiwi assets.
 *
 * @MediaSource(
 *   id = "assetkiwi_asset",
 *   label = @Translation("asset.kiwi Asset"),
 *   description = @Translation("Use assets from asset.kiwi."),
 *   allowed_field_types = {"string"},
 *   default_thumbnail_filename = "assetkiwi.png",
 *   forms = {
 *     "media_library_add" = "\Drupal\assetkiwi_connect\Form\AssetKiwiAddForm",
 *   }
 * )
 */
class AssetKiwiAsset extends MediaSourceBase {

  protected AssetKiwiClient $assetKiwiClient;

  protected LoggerInterface $logger;

  protected MimeIconResolver $mimeIconResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->assetKiwiClient = $container->get('assetkiwi_connect.client');
    $instance->logger = $container->get('logger.factory')->get('assetkiwi_connect');
    $instance->mimeIconResolver = $container->get('assetkiwi_connect.mime_icon_resolver');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes(): array {
    return [
      'uuid' => $this->t('Asset UUID'),
      'original_name' => $this->t('Original filename'),
      'mime_type' => $this->t('MIME type'),
      'size' => $this->t('File size (bytes)'),
      'url' => $this->t('Asset URL'),
      'alt_text' => $this->t('Alt text'),
      'description' => $this->t('Description'),
      'width' => $this->t('Width (px)'),
      'height' => $this->t('Height (px)'),
      'thumbnail_uri' => $this->t('Local thumbnail URI'),
      'thumb_url' => $this->t('Thumbnail variant URL'),
      'medium_url' => $this->t('Medium variant URL'),
      'large_url' => $this->t('Large variant URL'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(MediaInterface $media, $name): mixed {
    $sourceField = $this->configuration['source_field'] ?? '';
    if (empty($sourceField)) {
      return NULL;
    }

    $uuid = $media->get($sourceField)->getString();
    if (empty($uuid)) {
      return NULL;
    }

    // Fall back to branded default icon on any thumbnail failure.
    if ($name === 'thumbnail_uri') {
      try {
        $asset = $this->assetKiwiClient->getAsset($uuid);
        if (!empty($asset)) {
          $data = $this->assetKiwiClient->normalizeAsset($asset);
          $thumbnail_uri = $this->getThumbnailUri($data);
          if ($thumbnail_uri !== NULL) {
            return $thumbnail_uri;
          }
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('Could not resolve asset.kiwi thumbnail for media @id (@uuid): @message', [
          '@id' => $media->id() ?? 'new',
          '@uuid' => $uuid,
          '@message' => $e->getMessage(),
        ]);
      }
      return parent::getMetadata($media, $name);
    }

    $asset = $this->assetKiwiClient->getAsset($uuid);
    if (empty($asset)) {
      return NULL;
    }

    $data = $this->assetKiwiClient->normalizeAsset($asset);

    $displayName = $this->assetKiwiClient->getAssetDisplayName($data);
    return match ($name) {
      'default_name' => $displayName !== 'Untitled' ? $displayName : parent::getMetadata($media, $name),
      'uuid' => $data['uuid'] ?? $data['id'] ?? NULL,
      'original_name' => $data['original_name'] ?? $data['name'] ?? $data['filename'] ?? NULL,
      'mime_type' => $data['mime_type'] ?? $data['mimeType'] ?? NULL,
      'size' => $data['size'] ?? $data['file_size'] ?? NULL,
      'url' => $data['url'] ?? $data['download_url'] ?? NULL,
      'alt_text' => $data['alt_text'] ?? $data['alt'] ?? $data['original_name'] ?? NULL,
      'description' => $data['description'] ?? NULL,
      'width' => $data['width'] ?? NULL,
      'height' => $data['height'] ?? NULL,
      'thumbnail_uri' => $this->getThumbnailUri($data),
      'thumb_url' => $this->assetKiwiClient->resolveVariantUrl($data, 'thumb'),
      'medium_url' => $this->assetKiwiClient->resolveVariantUrl($data, 'medium'),
      'large_url' => $this->assetKiwiClient->resolveVariantUrl($data, 'large'),
      default => parent::getMetadata($media, $name),
    };
  }


  protected function getThumbnailUri(array $data): ?string {
    $uuid = $data['uuid'] ?? NULL;
    $mime_type = $data['mime_type'] ?? $data['mimeType'] ?? '';

    // Non-image assets get a stable bundled file-type icon rather than a DAM
    // page-render preview: the preview is often blank/absent and renders as an
    // empty thumbnail. Returning NULL here would fall back to core's branded
    // default; a per-type icon is clearer. Returns NULL if the icon isn't
    // present (install hook not run) so core's default still applies.
    if (!str_starts_with($mime_type, 'image/')) {
      return $this->fileTypeIconUri($mime_type);
    }

    // Images: real thumbnail. Prefer a generated derivative, else the original.
    $thumb_url = $this->assetKiwiClient->resolveVariantUrl($data, 'thumb')
      ?? $this->assetKiwiClient->resolveVariantUrl($data, 'preview')
      ?? ($data['url'] ?? NULL);

    if (!$uuid || !$thumb_url) {
      return NULL;
    }
    try {
      return $this->assetKiwiClient->cacheThumbnail($uuid, $thumb_url);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Failed to cache asset.kiwi thumbnail for @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Returns the local URI of the bundled file-type icon for a MIME type.
   *
   * Icons are copied to public://assetkiwi_icons on install/update
   * (_assetkiwi_connect_copy_media_icons()). Returns NULL when the icon is
   * missing so the caller falls back to core's branded default thumbnail.
   */
  protected function fileTypeIconUri(string $mimeType): ?string {
    $bucket = $this->mimeIconResolver->getIconBucket($mimeType);
    $uri = 'public://assetkiwi_icons/' . $bucket . '.png';
    return file_exists($uri) ? $uri : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'allowed_mime_types' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['allowed_mime_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed asset types'),
      '#description' => $this->t('Restrict this media type to specific asset types. If none are selected, all types are allowed.'),
      '#options' => [
        'image' => $this->t('Images (image/*)'),
        'video' => $this->t('Video (video/*)'),
        'audio' => $this->t('Audio (audio/*)'),
        'document' => $this->t('Documents (application/pdf, application/msword, etc.)'),
      ],
      '#default_value' => $this->configuration['allowed_mime_types'] ?? [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);

    $selected = $form_state->getValue('allowed_mime_types');
    // Filter to only the checked values (checkboxes returns key => value|0).
    $this->configuration['allowed_mime_types'] = array_values(array_filter((array) $selected));

    // Set the default widget for the source field to the asset.kiwi Browser.
    $this->setDefaultWidget();
  }

  /** Only sets the widget if no component is already configured. */
  protected function setDefaultWidget(): void {
    $bundle = $this->getConfiguration()['bundle'] ?? '';
    if (empty($bundle)) {
      return;
    }

    $field_name = $this->getSourceFieldName();
    $form_display = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load('media.' . $bundle . '.default');

    if ($form_display && !$form_display->getComponent($field_name)) {
      $form_display->setComponent($field_name, [
        'type' => 'assetkiwi_browser',
        'weight' => 0,
        'settings' => [],
      ]);
      $form_display->save();
    }
  }

  /**
   * Returns the configured allowed MIME type groups.
   *
   * @return string[]
   *   An array of allowed type keys ('image', 'video', 'audio', 'document'),
   *   or an empty array if all types are allowed.
   */
  public function getAllowedMimeTypes(): array {
    return array_values(array_filter((array) ($this->configuration['allowed_mime_types'] ?? [])));
  }

  public function getSourceFieldName(): string {
    return 'field_assetkiwi_uuid';
  }

  /**
   * {@inheritdoc}
   */
  protected function createSourceFieldStorage() {
    $field_name = $this->getSourceFieldName();
    $existing = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load('media.' . $field_name);

    if ($existing) {
      return $existing;
    }

    return parent::createSourceFieldStorage();
  }

}
