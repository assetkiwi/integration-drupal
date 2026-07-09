<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\assetkiwi_connect\Plugin\media\Source\AssetKiwiAsset;

#[FieldWidget(
  id: 'assetkiwi_browser',
  label: new TranslatableMarkup('asset.kiwi Browser'),
  description: new TranslatableMarkup('Provides a visual browser to select assets from asset.kiwi.'),
  field_types: ['string'],
)]
class AssetKiwiBrowserWidget extends WidgetBase {

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

    if (!empty($value)) {
      $element['preview']['info'] = [
        '#markup' => '<div class="assetkiwi-widget-selected"><span class="assetkiwi-widget-uuid">' . $this->t('Selected asset: @uuid', ['@uuid' => $value]) . '</span></div>',
      ];
      $element['preview']['thumbnail'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['assetkiwi-widget-thumbnail'],
          'data-uuid' => $value,
        ],
      ];
    }
    else {
      $element['preview']['empty'] = [
        '#markup' => '<div class="assetkiwi-widget-empty">' . $this->t('No asset selected.') . '</div>',
      ];
    }

    $browser_query = ['modal' => '1'];

    // Pass allowed asset types so the standalone browser respects the same
    // restrictions configured on the media type's source plugin.
    $allowed_types = $this->getAllowedTypesFromField($items);
    if (!empty($allowed_types)) {
      $browser_query['allowed_types'] = implode(',', $allowed_types);
    }

    $browser_url = Url::fromRoute('assetkiwi_connect.browser', [], ['query' => $browser_query])->toString();

    $element['browse_button'] = [
      '#type' => 'button',
      '#value' => empty($value) ? $this->t('Browse DAM') : $this->t('Replace asset'),
      '#attributes' => [
        'class' => ['assetkiwi-browse-btn', 'button', 'button--primary'],
        'data-browser-url' => $browser_url,
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
          if ($source instanceof asset.kiwiAsset) {
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
