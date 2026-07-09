<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Form;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\Plugin\media\Source\AssetKiwiAsset;
use Drupal\media\MediaTypeInterface;
use Drupal\media_library\Form\AddFormBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AssetKiwiAddForm extends AddFormBase {

  /**
   * The asset.kiwi API client.
   */
  protected AssetKiwiClient $assetKiwiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->assetKiwiClient = $container->get('assetkiwi_connect.client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'assetkiwi_media_library_add_form';
  }

  /**
   * Returns the AJAX configuration for buttons in this form.
   *
   * AJAX forms are automatically posted to <current> instead of
   * $form['#action']. We need to explicitly set the URL to the media library
   * route so the form is submitted correctly when inside a dialog.
   *
   * @see https://www.drupal.org/project/drupal/issues/2504115
   */
  protected function getAjaxSettings(FormStateInterface $form_state): array {
    return [
      'callback' => '::updateFormCallback',
      'wrapper' => 'media-library-wrapper',
      'url' => Url::fromRoute('media_library.ui'),
      'options' => [
        'query' => $this->getMediaLibraryState($form_state)->all() + [
            FormBuilderInterface::AJAX_FORM_REQUEST => TRUE,
          ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function buildInputElement(array $form, FormStateInterface $form_state): array {
    $ajax_settings = $this->getAjaxSettings($form_state);

    $search = $form_state->getValue('dam_search', '') ?: '';
    $collection = $form_state->getValue('dam_collection', '') ?: '';
    $tag = $form_state->getValue('dam_tag', '') ?: '';
    $type = $form_state->getValue('dam_type', '') ?: '';
    $page = (int)($form_state->getValue('dam_page', 1) ?: 1);

    // Determine which MIME type groups are allowed by this media type's source.
    $allowed_mime_types = $this->getAllowedMimeTypes($form_state);

    // If the user chose a type filter, restrict to the intersection of their
    // choice and the allowed types. If nothing is allowed-restricted, use
    // the user's choice as-is.
    $effective_type = $type;
    if (!empty($allowed_mime_types) && !empty($type)) {
      // Only honour the user's filter if it is among the allowed types.
      if (!in_array($type, $allowed_mime_types, TRUE)) {
        $effective_type = '';
      }
    }
    elseif (!empty($allowed_mime_types) && empty($type) && count($allowed_mime_types) === 1) {
      // Single allowed type — apply it automatically.
      $effective_type = reset($allowed_mime_types);
    }

    $params = array_filter([
      'search' => $search,
      'collection' => $collection,
      'tag' => $tag,
      'type' => $effective_type,
      'page' => $page,
    ]);

    $assets = $this->assetKiwiClient->getAssets($params);
    $collections = $this->assetKiwiClient->getCollections();
    $tags = $this->assetKiwiClient->getTags();

    $items = $assets['data'] ?? $assets;
    $pager = $this->assetKiwiClient->normalizePager($assets, $page);

    $form['dam_filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['assetkiwi-ml-filters']],
    ];

    $form['dam_filters']['dam_search'] = [
      '#type' => 'search',
      '#title' => $this->t('Search'),
      '#default_value' => $search,
      '#size' => 30,
      '#attributes' => ['placeholder' => $this->t('Keywords…')],
    ];

    $collection_options = ['' => $this->t('— Any collection —')];
    foreach (($collections['data'] ?? $collections) as $col) {
      $collection_options[$col['id'] ?? ''] = $col['name'] ?? '';
    }
    $form['dam_filters']['dam_collection'] = [
      '#type' => 'select',
      '#title' => $this->t('Collection'),
      '#options' => $collection_options,
      '#default_value' => $collection,
    ];

    $tag_options = ['' => $this->t('— Any tag —')];
    foreach (($tags['data'] ?? $tags) as $t) {
      $tag_options[$t['id'] ?? ''] = $t['name'] ?? '';
    }
    $form['dam_filters']['dam_tag'] = [
      '#type' => 'select',
      '#title' => $this->t('Tag'),
      '#options' => $tag_options,
      '#default_value' => $tag,
    ];

    // Build the type filter dropdown. Hide it when only one type is allowed
    // (it would be redundant) and restrict options when a subset is allowed.
    $all_type_options = [
      'image' => $this->t('Images'),
      'video' => $this->t('Video'),
      'audio' => $this->t('Audio'),
      'document' => $this->t('Documents'),
    ];

    $show_type_filter = TRUE;
    if (count($allowed_mime_types) === 1) {
      // Single allowed type — the filter is always pre-applied; hide the UI.
      $show_type_filter = FALSE;
    }

    if ($show_type_filter) {
      $type_options = ['' => $this->t('— Any type —')];
      if (!empty($allowed_mime_types)) {
        // Restrict dropdown to only the allowed types.
        foreach ($allowed_mime_types as $key) {
          if (isset($all_type_options[$key])) {
            $type_options[$key] = $all_type_options[$key];
          }
        }
      }
      else {
        $type_options += $all_type_options;
      }

      $form['dam_filters']['dam_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Type'),
        '#options' => $type_options,
        '#default_value' => $type,
      ];
    }

    $form['dam_filters']['dam_filter_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
      '#submit' => ['::filterSubmit'],
      '#ajax' => $ajax_settings,
      '#limit_validation_errors' => [
        ['dam_search'],
        ['dam_collection'],
        ['dam_tag'],
        ['dam_type'],
      ],
    ];

    $form['dam_page'] = [
      '#type' => 'hidden',
      '#default_value' => $page,
    ];

    $form['dam_assets'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['assetkiwi-ml-grid']],
    ];

    if (!empty($items)) {
      $form['dam_assets']['help'] = [
        '#markup' => '<p class="assetkiwi-ml-help">' . $this->t('Click <em>Select</em> to add an asset.') . '</p>',
      ];

      foreach ($items as $index => $asset) {
        $uuid = $asset['uuid'] ?? $asset['id'] ?? '';
        $name = $this->assetKiwiClient->getAssetDisplayName($asset);
        $mime = $asset['mime_type'] ?? $asset['mimeType'] ?? '';
        $thumb_url = $this->assetKiwiClient->resolveVariantUrl($asset, 'thumb') ?? '';
        $display_url = $thumb_url ?: ($asset['url'] ?? $asset['download_url'] ?? '');

        $form['dam_assets']['asset_' . $index] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['assetkiwi-ml-card'],
          ],
        ];

        if (str_starts_with($mime, 'image') && $display_url) {
          $form['dam_assets']['asset_' . $index]['thumb'] = [
            '#markup' => '<div class="assetkiwi-ml-card__preview"><img src="' . htmlspecialchars($display_url) . '" alt="' . htmlspecialchars($name) . '" loading="lazy" /></div>',
          ];
        }
        else {
          $form['dam_assets']['asset_' . $index]['icon'] = [
            '#markup' => '<div class="assetkiwi-ml-card__preview"><div class="assetkiwi-ml-card__file-icon"><span>' . htmlspecialchars($mime ?: 'file') . '</span></div></div>',
          ];
        }

        $form['dam_assets']['asset_' . $index]['name'] = [
          '#markup' => '<div class="assetkiwi-ml-card__name" title="' . htmlspecialchars($name) . '">' . htmlspecialchars(mb_strlen($name) > 35 ? mb_substr($name, 0, 32) . '…' : $name) . '</div>',
        ];

        $form['dam_assets']['asset_' . $index]['select_btn'] = [
          '#type' => 'submit',
          '#value' => $this->t('Select'),
          '#name' => 'select_asset_' . $index,
          '#submit' => ['::selectAssetSubmit'],
          '#ajax' => $ajax_settings,
          '#limit_validation_errors' => [],
          '#attributes' => [
            'class' => ['assetkiwi-ml-card__select', 'button', 'button--small'],
            'data-asset-uuid' => $uuid,
            'data-asset-name' => $name,
          ],
        ];

        $form['dam_assets']['asset_' . $index]['select'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Select @name', ['@name' => $name]),
          '#title_display' => 'invisible',
          '#attributes' => [
            'class' => ['assetkiwi-ml-card__checkbox'],
            'data-uuid' => $uuid,
            'data-name' => $name,
          ],
          '#default_value' => FALSE,
        ];

        $form['dam_assets']['asset_' . $index]['uuid_' . $index] = [
          '#type' => 'hidden',
          '#default_value' => $uuid,
        ];
      }
    }
    else {
      $form['dam_assets']['empty'] = [
        '#markup' => '<div class="assetkiwi-ml-empty">' . $this->t('No assets found. Try adjusting your filters.') . '</div>',
      ];
    }

    if ($pager['last_page'] > 1) {
      $form['dam_pager'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['assetkiwi-ml-pager']],
      ];

      if ($pager['current_page'] > 1) {
        $form['dam_pager']['prev'] = [
          '#type' => 'submit',
          '#value' => $this->t('‹ Previous'),
          '#submit' => ['::pagerPrevSubmit'],
          '#ajax' => $ajax_settings,
      '#limit_validation_errors' => [['dam_page'], ['dam_search'], ['dam_collection'], ['dam_tag'], ['dam_type']],
      '#attributes' => ['class' => ['button', 'button--small']],
    ];
  }

  if ($pager['current_page'] < $pager['last_page']) {
    $form['dam_pager']['next'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next ›'),
      '#submit' => ['::pagerNextSubmit'],
      '#ajax' => $ajax_settings,
      '#limit_validation_errors' => [['dam_page'], ['dam_search'], ['dam_collection'], ['dam_tag'], ['dam_type']],
          '#attributes' => ['class' => ['button', 'button--small']],
        ];
      }
    }

    // Bulk import button for multi-select.
    $form['dam_bulk_import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import selected'),
      '#submit' => ['::bulkImportSubmit'],
      '#ajax' => $ajax_settings,
      '#attributes' => [
        'class' => ['button', 'button--primary', 'js-assetkiwi-bulk-import'],
      ],
      '#access' => !empty($items),
    ];

    $form['#attached']['library'][] = 'assetkiwi_connect/media_library';

    return $form;
  }

  public function filterSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->setValue('dam_page', 1);
    $form_state->setRebuild();
  }

  public function pagerPrevSubmit(array &$form, FormStateInterface $form_state): void {
    $page = max(1, (int)$form_state->getValue('dam_page', 1) - 1);
    $form_state->setValue('dam_page', $page);
    $form_state->setRebuild();
  }

  public function pagerNextSubmit(array &$form, FormStateInterface $form_state): void {
    $page = (int)$form_state->getValue('dam_page', 1) + 1;
    $form_state->setValue('dam_page', $page);
    $form_state->setRebuild();
  }

  public function selectAssetSubmit(array &$form, FormStateInterface $form_state): void {
    $triggering = $form_state->getTriggeringElement();
    $uuid = $triggering['#attributes']['data-asset-uuid'] ?? '';

    if (empty($uuid)) {
      return;
    }

    // processInputValues() creates unsaved media entities from source field
    // values, then transitions to step 2 (entity form) or saves directly.
    $this->processInputValues([$uuid], $form, $form_state);
  }

  public function bulkImportSubmit(array &$form, FormStateInterface $form_state): void {
    $selected = [];
    $values = $form_state->getValues();
    foreach ($values as $key => $value) {
      if (preg_match('/^select_asset_(\d+)$/', $key, $m) && $value) {
        $index = $m[1];
        $uuid = $values['uuid_' . $index] ?? '';
        if (!empty($uuid)) {
          $selected[] = $uuid;
        }
      }
    }

    if (empty($selected)) {
      $this->messenger()->addWarning($this->t('No assets selected.'));
      $form_state->setRebuild();
      return;
    }

    $this->processInputValues($selected, $form, $form_state);
  }

  /**
   * Returns the allowed MIME type groups configured on the media type source.
   *
   * @return string[]
   *   Allowed type keys (e.g. ['image', 'document']), or empty array for all.
   */
  protected function getAllowedMimeTypes(FormStateInterface $form_state): array {
    try {
      $media_type = $this->getMediaType($form_state);
      $source = $media_type->getSource();
      if ($source instanceof asset.kiwiAsset) {
        return $source->getAllowedMimeTypes();
      }
    }
    catch (\Exception $e) {
      // Cannot determine media type; fall back to allowing all types.
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function getSourceFieldName($media_type): string {
    $source = $media_type->getSource();
    $source_field = $source->getSourceFieldDefinition($media_type);
    return $source_field ? $source_field->getName() : 'field_assetkiwi_uuid';
  }

  /**
   * {@inheritdoc}
   *
   * Overrides the base to fetch asset metadata from the DAM API and
   * pre-populate the media entity name, alt text, and title fields.
   */
  protected function createMediaFromValue(MediaTypeInterface $media_type, EntityStorageInterface $media_storage, $source_field_name, $source_field_value) {
    $media = parent::createMediaFromValue($media_type, $media_storage, $source_field_name, $source_field_value);

    $asset = $this->assetKiwiClient->getAsset((string)$source_field_value);
    if (!empty($asset)) {
      $data = $this->assetKiwiClient->normalizeAsset($asset);

      $name = $this->assetKiwiClient->getAssetDisplayName($data) ?: NULL;
      if ($name && $name !== 'Untitled') {
        $media->setName($name);
      }

      $alt_text = $data['alt_text'] ?? $data['alt'] ?? $name ?? NULL;
      if ($alt_text) {
        // Try common alt text field names.
        foreach (['field_media_image_alt', 'field_alt_text', 'field_media_alt'] as $alt_field) {
          if ($media->hasField($alt_field)) {
            $media->set($alt_field, $alt_text);
            break;
          }
        }
      }

      $title = $data['title'] ?? $data['name'] ?? $name ?? NULL;
      if ($title) {
        foreach (['field_media_image_title', 'field_title', 'field_media_title'] as $title_field) {
          if ($media->hasField($title_field)) {
            $media->set($title_field, $title);
            break;
          }
        }
      }

      // Also try to populate metadata-mapped fields via the source plugin.
      $source = $media_type->getSource();
      $field_map = $media_type->getFieldMap();
      foreach ($field_map as $metadata_attribute => $entity_field) {
        if ($media->hasField($entity_field) && $media->get($entity_field)->isEmpty()) {
          $value = $source->getMetadata($media, $metadata_attribute);
          if ($value !== NULL) {
            $media->set($entity_field, $value);
          }
        }
      }

      // Download the original file from asset.kiwi.and attach it to the media entity.
      try {
        $fileInfo = $this->assetKiwiClient->downloadAssetFile((string)$source_field_value);
        if ($fileInfo !== NULL) {
          // Try to set the file on common file fields.
          foreach (['field_media_file', 'field_assetkiwi_file', 'field_file'] as $fileField) {
            if ($media->hasField($fileField)) {
              $media->set($fileField, ['target_id' => $fileInfo['fid']]);
              break;
            }
          }

          // For image media, try to set the image field if this is an image.
          if (str_starts_with($fileInfo['mime'] ?? '', 'image/')) {
            foreach (['field_media_image', 'field_image'] as $imageField) {
              if ($media->hasField($imageField)) {
                $media->set($imageField, [
                  'target_id' => $fileInfo['fid'],
                  'alt' => $data['alt_text'] ?? $data['alt'] ?? $name ?? '',
                ]);
                break;
              }
            }
          }
        }
      } catch (\Exception $e) {
        // Log but don't block import — the UUID reference still works.
        \Drupal::logger('assetkiwi_connect')->warning('Failed to download file for @uuid: @message', [
          '@uuid' => $source_field_value,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $media;
  }

}
