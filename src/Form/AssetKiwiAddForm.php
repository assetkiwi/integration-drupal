<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\ExistingMediaResolver;
use Drupal\assetkiwi_connect\Plugin\media\Source\AssetKiwiAsset;
use Drupal\media\MediaTypeInterface;
use Drupal\media_library\Form\AddFormBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AssetKiwiAddForm extends AddFormBase {

  protected AssetKiwiClient $assetKiwiClient;

  protected ExistingMediaResolver $existingMediaResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->assetKiwiClient = $container->get('assetkiwi_connect.client');
    $instance->existingMediaResolver = $container->get('assetkiwi_connect.existing_media_resolver');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'assetkiwi_media_library_add_form';
  }

  /**
   * AJAX config for the picker's confirm trigger.
   */
  protected function getAjaxSettings(FormStateInterface $form_state): array {
    return [
      'callback' => '::confirmSelectionCallback',
      'wrapper' => 'media-library-add-form-wrapper',
      'url' => Url::fromRoute('media_library.ui'),
      'options' => [
        'query' => $this->getMediaLibraryState($form_state)->all() + [
            FormBuilderInterface::AJAX_FORM_REQUEST => TRUE,
          ],
      ],
    ];
  }

  /**
   * AJAX callback for the picker's confirm trigger.
   *
   * New media → show step 2 review form. Existing-only → insert media and close dialog.
   */
  public function confirmSelectionCallback(array &$form, FormStateInterface $form_state): AjaxResponse|array {
    if ($form_state::hasAnyErrors()) {
      $response = new AjaxResponse();
      $response->addCommand(new ReplaceCommand('#media-library-add-form-wrapper', $form));
      return $response;
    }

    if (!empty($this->getAddedMediaItems($form_state))) {
      return $this->updateFormCallback($form, $form_state);
    }

    // Existing-only: insert the stashed media ids (access-checked) and close.
    $media = $this->entityTypeManager->getStorage('media')
      ->loadMultiple(array_filter((array) $form_state->get('assetkiwi_existing_ids')));
    $media_ids = array_values(array_map(
      static fn($media_item) => $media_item->id(),
      array_filter($media, static fn($media_item) => $media_item->access('view')),
    ));

    $state = $this->getMediaLibraryState($form_state);
    return $this->openerResolver->get($state)
      ->getSelectionResponse($state, $media_ids)
      ->addCommand(new CloseDialogCommand());
  }

  /**
   * {@inheritdoc}
   *
   * Repoints the primary "Save" button from ::updateLibrary (which returns to
   * the media library browse grid) to ::updateWidget (insert into the field +
   * close). The browse grid is suppressed for asset.kiwi media types
   * (AssetKiwiMediaLibraryUiBuilder), so "return to library" dead-ends on the
   * bare picker instead of inserting the just-created media — hence a new
   * import appeared to do nothing after Save. updateWidget matches the
   * existing-media path (confirmSelectionCallback), so both routes end by
   * inserting and closing.
   */
  protected function buildActions(array $form, FormStateInterface $form_state): array {
    $actions = parent::buildActions($form, $form_state);
    if (isset($actions['save_select']['#ajax']['callback'])) {
      $actions['save_select']['#ajax']['callback'] = '::updateWidget';
      $actions['save_select']['#value'] = $this->t('Save and insert');
    }
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildInputElement(array $form, FormStateInterface $form_state): array {
    $ajax_settings = $this->getAjaxSettings($form_state);
    $allowed_mime_types = $this->getAllowedMimeTypes($form_state);

    // getAvailableSlots() already accounts for the host field's storage
    // cardinality minus whatever is already selected; negative means
    // unlimited. The picker treats 0/negative as "no cap".
    $available_slots = $this->getMediaLibraryState($form_state)->getAvailableSlots();

    // The picker JS mounts here and drives its own search/filter/pagination via JSON endpoints.
    $picker_config = [
      'mode' => 'multi',
      'allowedTypes' => array_values($allowed_mime_types),
      'maxSelections' => max(0, $available_slots),
      // Lets the picker flag assets that already have a Drupal media entity
      // (see AssetBrowserController::assets()) instead of listing them as
      // fresh imports.
      'bundle' => $this->getMediaType($form_state)->id(),
      'endpoints' => [
        'assets' => Url::fromRoute('assetkiwi_connect.api.assets')->toString(),
        'facets' => Url::fromRoute('assetkiwi_connect.api.facets')->toString(),
      ],
    ];

    $form['dam_picker'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['assetkiwi-ml-picker'],
        'data-assetkiwi-picker' => Json::encode($picker_config),
        'data-assetkiwi-medialibrary' => 'medialibrary',
      ],
    ];

    $form['selected_uuids'] = [
      '#type' => 'hidden',
      '#default_value' => '',
      '#attributes' => [
        'class' => ['assetkiwi-ml-selected-uuids'],
      ],
    ];

    // Hidden trigger clicked programmatically by the picker JS.
    $form['dam_bulk_import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Use selected'),
      '#submit' => ['::bulkImportSubmit'],
      '#ajax' => $ajax_settings,
      '#attributes' => [
        'class' => ['assetkiwi-ml-bulk-import-trigger'],
        'hidden' => 'hidden',
      ],
      '#limit_validation_errors' => [['selected_uuids']],
    ];

    $form['#attached']['library'][] = 'assetkiwi_connect/media_library';

    return $form;
  }

  /**
   * Submit handler for the picker widget's hidden confirm trigger.
   *
   * Splits the selection: UUIDs that already have a Drupal media entity are
   * stashed for direct insertion (no duplicate created); genuinely new UUIDs
   * go through processInputValues(), which creates unsaved media entities and
   * advances to step 2 (the entity review form). confirmSelectionCallback()
   * then finishes each case.
   *
   * @see js/assetkiwi-picker.js
   * @see ::confirmSelectionCallback()
   */
  public function bulkImportSubmit(array &$form, FormStateInterface $form_state): void {
    $raw = (string)$form_state->getValue('selected_uuids', '');
    $selected = array_values(array_filter(array_map('trim', explode(',', $raw))));

    if (empty($selected)) {
      $this->messenger()->addWarning($this->t('No assets selected.'));
      $form_state->setRebuild();
      return;
    }

    $media_type = $this->getMediaType($form_state);
    $existing_uuids = $this->existingMediaResolver->findExisting($media_type, $selected);
    $existing_ids = array_values($existing_uuids);
    $new_uuids = array_values(array_diff($selected, array_keys($existing_uuids)));

    // Stash the already-imported media ids for confirmSelectionCallback().
    $form_state->set('assetkiwi_existing_ids', $existing_ids);

    if (!empty($new_uuids)) {
      // New (or mixed) selection: carry any already-imported media as
      // pre-selected so they ride along through the step-2 review, then create
      // the new ones. processInputValues() reads the 'current_selection' value
      // to seed getPreSelectedMediaItems().
      if (!empty($existing_ids)) {
        $current = array_filter(explode(',', (string)$form_state->getValue('current_selection', '')));
        $form_state->setValue('current_selection', implode(',', array_unique(array_merge($current, $existing_ids))));
      }
      $this->processInputValues($new_uuids, $form, $form_state);
      return;
    }

    // Existing-only: nothing to create. confirmSelectionCallback() inserts the
    // stashed ids via the opener and closes the dialog. Rebuild so the AJAX
    // callback runs.
    $form_state->setRebuild();
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
      if ($source instanceof AssetKiwiAsset) {
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

  /** {@inheritdoc} */
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
      // Skipped when assets should be served directly from asset.kiwi (its CDN) —
      // the source UUID field plus AssetKiwiImageFormatter already handle display.
      if (!$this->assetKiwiClient->shouldServeFromCdn()) {
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
    }

    return $media;
  }

}
