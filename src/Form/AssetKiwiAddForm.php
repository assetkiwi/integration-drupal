<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
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

  protected ElementInfoManagerInterface $elementInfo;

  protected FileSystemInterface $fileSystem;

  protected FileUsageInterface $fileUsage;

  /**
   * asset.kiwi's own per-file upload limit (StoreAssetRequest: 512 MB).
   */
  protected const API_MAX_UPLOAD_BYTES = 512 * 1024 * 1024;

  /**
   * Upload extensions per allowed asset type group (AssetKiwiAsset config).
   */
  protected const UPLOAD_EXTENSIONS = [
    'image' => 'jpg jpeg png gif webp avif svg tif tiff',
    'video' => 'mp4 m4v mov webm',
    'audio' => 'mp3 m4a wav ogg aac flac',
    'document' => 'pdf doc docx xls xlsx ppt pptx odt ods odp txt csv rtf',
  ];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->assetKiwiClient = $container->get('assetkiwi_connect.client');
    $instance->existingMediaResolver = $container->get('assetkiwi_connect.existing_media_resolver');
    $instance->elementInfo = $container->get('element_info');
    $instance->fileSystem = $container->get('file_system');
    $instance->fileUsage = $container->get('file.usage');
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
   *
   * Like core's FileUploadForm/OEmbedForm, the wrapper is the whole media
   * library UI ('media-library-wrapper'), not just this form: step 2 (the
   * added-media review form) replaces the menu + content, and core's
   * AddFormBase then swaps '#media-library-add-form-wrapper' back for the
   * full UI (menu included) when the last added item is removed, or on
   * updateLibrary(). Targeting only the add-form wrapper here left the menu
   * in place, so returning to the library nested a second menu + content
   * inside the dialog.
   */
  protected function getAjaxSettings(FormStateInterface $form_state): array {
    return [
      'callback' => '::confirmSelectionCallback',
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

    // Nothing usable (e.g. empty selection or every upload failed): stay on
    // the form so the messages set by the submit handler are shown, instead
    // of closing the dialog without inserting anything.
    if (empty($media_ids)) {
      $response = new AjaxResponse();
      $response->addCommand(new ReplaceCommand('#media-library-add-form-wrapper', $form));
      return $response;
    }

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

    $form = $this->buildUploadElement($form, $form_state);

    $form['#attached']['library'][] = 'assetkiwi_connect/media_library';

    return $form;
  }

  /**
   * Whether the current user may upload new files to asset.kiwi from here.
   *
   * Gated by the "Allow uploading to asset.kiwi" setting and the
   * "upload assetkiwi assets" permission. When FALSE the upload element is
   * built with #access FALSE, so Form API ignores any submitted file and the
   * upload button can't be the triggering element.
   */
  protected function isUploadAllowed(): bool {
    return (bool) ($this->config('assetkiwi_connect.settings')->get('allow_upload') ?? TRUE)
      && $this->currentUser()->hasPermission('upload assetkiwi assets');
  }

  /**
   * Adds the "Upload to asset.kiwi" managed_file element.
   *
   * Mirrors core's FileUploadForm: the managed_file's own upload button is
   * repointed (::processUploadElement) to push the file to asset.kiwi and
   * continue to the same step-2 review form the picker uses.
   */
  protected function buildUploadElement(array $form, FormStateInterface $form_state): array {
    $state = $this->getMediaLibraryState($form_state);
    if (!$state->hasSlotsAvailable()) {
      return $form;
    }
    $slots = $state->getAvailableSlots();

    $allowed = $this->getAllowedMimeTypes($form_state) ?: array_keys(static::UPLOAD_EXTENSIONS);
    $extensions = implode(' ', array_intersect_key(static::UPLOAD_EXTENSIONS, array_flip($allowed)));
    $validators = [
      'FileExtension' => ['extensions' => $extensions],
      'FileSizeLimit' => ['fileLimit' => min(Environment::getUploadMaxSize(), static::API_MAX_UPLOAD_BYTES)],
    ];

    $form['assetkiwi_upload_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Upload to asset.kiwi'),
      '#open' => FALSE,
      '#access' => $this->isUploadAllowed(),
      '#attributes' => ['class' => ['assetkiwi-ml-upload']],
    ];

    $process = (array) $this->elementInfo->getInfoProperty('managed_file', '#process', []);
    $form['assetkiwi_upload_container']['assetkiwi_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->formatPlural($slots, 'Add file', 'Add files'),
      '#description' => $this->t('The file is uploaded to your asset.kiwi library and then added here.'),
      '#process' => array_merge($process, ['::processUploadElement']),
      '#upload_validators' => $validators,
      '#upload_location' => 'temporary://assetkiwi_upload',
      '#multiple' => $slots != 1,
    ];
    $form['assetkiwi_upload_container']['help'] = [
      '#theme' => 'file_upload_help',
      '#upload_validators' => $validators,
      '#cardinality' => $slots,
    ];

    return $form;
  }

  /**
   * #process callback: routes the managed_file upload button to asset.kiwi.
   */
  public function processUploadElement(array $element, FormStateInterface $form_state): array {
    $element['upload_button']['#submit'] = ['::uploadToAssetKiwiSubmit'];
    $element['upload_button']['#limit_validation_errors'] = [
      ['assetkiwi_upload'],
      ['current_selection'],
    ];
    $element['upload_button']['#ajax'] = $this->getAjaxSettings($form_state);
    return $element;
  }

  /**
   * Keeps only files that are safe to push to asset.kiwi and then delete.
   *
   * The fids come from the submitted form values. ManagedFile's value
   * callback already refuses other users' temporary files, but since this
   * handler deletes what it uploads, re-check explicitly: only temporary
   * files owned by the current user with no recorded usage (i.e. a fresh
   * transport upload) are processed. Anything else — a permanent file, or
   * someone else's, or one referenced elsewhere — is skipped untouched.
   *
   * @param \Drupal\file\FileInterface[] $files
   *   Loaded file entities.
   *
   * @return \Drupal\file\FileInterface[]
   *   The subset that may be uploaded and deleted.
   */
  protected function filterOwnTemporaryUploads(array $files): array {
    $uid = (int) $this->currentUser()->id();
    return array_filter($files, fn(FileInterface $file): bool =>
      $file->isTemporary()
      && (int) $file->getOwnerId() === $uid
      && empty($this->fileUsage->listUsage($file))
    );
  }

  /**
   * Submit handler: uploads the temporary Drupal files to asset.kiwi.
   *
   * Each file goes to POST /api/v1/assets (AssetKiwiClient::uploadAsset(),
   * needs the assets:write ability on the token). The resulting UUIDs —
   * including the existing one when asset.kiwi reports a duplicate — then
   * follow the picker's path (::bulkImportSubmit()), so already-imported
   * assets are reused and new ones open the step-2 review form.
   */
  public function uploadToAssetKiwiSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
    if (!$this->isUploadAllowed()) {
      $this->messenger()->addError($this->t('Uploading to asset.kiwi is not allowed.'));
      return;
    }

    $fids = (array) $form_state->getValue('assetkiwi_upload', []);
    /** @var \Drupal\file\FileInterface[] $files */
    $files = $this->filterOwnTemporaryUploads(
      $this->entityTypeManager->getStorage('file')->loadMultiple($fids),
    );
    $uuids = [];
    foreach ($files as $file) {
      $path = $this->fileSystem->realpath($file->getFileUri());
      $result = $path ? $this->assetKiwiClient->uploadAsset($path, $file->getFilename(), (string) $file->getMimeType()) : NULL;
      if ($result === NULL) {
        $this->messenger()->addError($this->t('Could not upload %file to asset.kiwi: @error', [
          '%file' => $file->getFilename(),
          '@error' => $this->assetKiwiClient->getLastError() ?? $this->t('unknown error'),
        ]));
      }
      else {
        $uuids[] = $result['uuid'];
      }
      // The local copy was only a transport; asset.kiwi now holds the file.
      $file->delete();
    }
    $form_state->setValue('assetkiwi_upload', []);

    if (empty($uuids)) {
      return;
    }

    $form_state->setValue('selected_uuids', implode(',', $uuids));
    $this->bulkImportSubmit($form, $form_state);
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
