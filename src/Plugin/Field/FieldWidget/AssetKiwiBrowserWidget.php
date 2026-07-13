<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Plugin\Field\FieldWidget;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\Plugin\media\Source\AssetKiwiAsset;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[FieldWidget(
  id: 'assetkiwi_browser',
  label: new TranslatableMarkup('asset.kiwi Browser'),
  description: new TranslatableMarkup('Provides a visual browser to select assets from asset.kiwi.'),
  field_types: ['string'],
)]
class AssetKiwiBrowserWidget extends WidgetBase {

  protected AssetKiwiClient $assetKiwiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->assetKiwiClient = $container->get('assetkiwi_connect.client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $value = $items[$delta]->value ?? '';

    $element['value'] = [
      '#type' => 'hidden',
      '#default_value' => $value,
      '#attributes' => [
        'class' => ['assetkiwi-uuid-field'],
        'data-assetkiwi-uuid' => TRUE,
      ],
    ];

    $element['preview'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['assetkiwi-widget-preview'],
      ],
    ];

    $asset_preview = !empty($value) ? $this->loadAssetPreview($value) : NULL;

    if ($asset_preview) {
      $element['preview']['selected'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['assetkiwi-widget-selected']],
      ];

      if (str_starts_with($asset_preview['mime'] ?? '', 'image') && !empty($asset_preview['thumbUrl'])) {
        $element['preview']['selected']['thumb'] = [
          '#theme' => 'image',
          '#uri' => $asset_preview['thumbUrl'],
          '#alt' => $asset_preview['name'] ?? '',
          '#attributes' => ['class' => ['assetkiwi-widget-thumb']],
        ];
      }

      $element['preview']['selected']['info'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['assetkiwi-widget-info']],
        'name' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $asset_preview['name'] ?? '',
          '#attributes' => ['class' => ['assetkiwi-widget-name']],
        ],
        'uuid' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('UUID: @uuid', ['@uuid' => $value]),
          '#attributes' => ['class' => ['assetkiwi-widget-uuid-label']],
        ],
      ];
    }
    elseif (!empty($value)) {
      // Show UUID as fallback when API is unreachable.
      $element['preview']['info'] = [
        '#markup' => '<div class="assetkiwi-widget-selected"><span class="assetkiwi-widget-uuid">' . $this->t('Selected asset: @uuid', ['@uuid' => $value]) . '</span></div>',
      ];
    }
    else {
      $element['preview']['empty'] = [
        '#markup' => '<div class="assetkiwi-widget-empty">' . $this->t('No asset selected.') . '</div>',
      ];
    }

    // Pass allowed asset types so the picker widget respects the same
    // restrictions configured on the media type's source plugin.
    $allowed_types = $this->getAllowedTypesFromField($items);

    $picker_config = [
      'mode' => 'single',
      'allowedTypes' => $allowed_types,
      'endpoints' => [
        'assets' => Url::fromRoute('assetkiwi_connect.api.assets')->toString(),
        'facets' => Url::fromRoute('assetkiwi_connect.api.facets')->toString(),
      ],
    ];

    $element['browse_button'] = [
      '#type' => 'button',
      '#value' => empty($value) ? $this->t('Browse DAM') : $this->t('Replace asset'),
      '#attributes' => [
        'class' => ['assetkiwi-browse-btn', 'button', 'button--primary'],
        'data-assetkiwi-picker' => Json::encode($picker_config),
      ],
      '#limit_validation_errors' => [],
      '#executes_submit_callback' => FALSE,
    ];

    if (!empty($value)) {
      $element['remove_button'] = [
        '#type' => 'button',
        '#value' => $this->t('Remove'),
        '#attributes' => [
          'class' => ['assetkiwi-remove-btn', 'button', 'button--danger'],
        ],
        '#limit_validation_errors' => [],
        '#executes_submit_callback' => FALSE,
      ];
    }

    $element['#attached']['library'][] = 'assetkiwi_connect/widget';

    return $element;
  }

  /**
   * Fetches the selected asset for preview rendering.
   */
  protected function loadAssetPreview(string $uuid): ?array {
    try {
      $asset = $this->assetKiwiClient->getAsset($uuid);
      if (empty($asset)) {
        return NULL;
      }
      return $this->assetKiwiClient->toPickerAsset($asset);
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * Returns allowed MIME type groups from the field's entity's media source.
   *
   * @return string[]
   *   Allowed type keys, or empty array if all types are allowed.
   */
  protected function getAllowedTypesFromField(FieldItemListInterface $items): array {
    try {
      $entity = $items->getEntity();
      if ($entity->getEntityTypeId() === 'media') {
        $media_type = $entity->bundle->entity;
        if ($media_type) {
          $source = $media_type->getSource();
          if ($source instanceof AssetKiwiAsset) {
            return $source->getAllowedMimeTypes();
          }
        }
      }
    }
    catch (\Exception $e) {
      // Cannot resolve media type; allow all types.
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    $result = [];
    foreach ($values as $delta => $value) {
      if (!empty($value['value'])) {
        $result[$delta] = ['value' => $value['value']];
      }
    }
    return $result;
  }

}
