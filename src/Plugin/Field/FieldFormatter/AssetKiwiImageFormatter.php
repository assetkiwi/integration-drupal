<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Renders an asset.kiwi asset UUID as an image using a configured variant.
 *
 * @FieldFormatter(
 *   id = "assetkiwi_image",
 *   label = @Translation("asset.kiwi Image"),
 *   field_types = {
 *     "string",
 *   }
 * )
 */
class AssetKiwiImageFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  protected AssetKiwiClient $assetKiwiClient;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->assetKiwiClient = $container->get('assetkiwi_connect.client');
    return $instance;
  }

  public static function defaultSettings(): array {
    return [
        'variant' => '',
        'image_loading' => 'lazy',
        'link_to_original' => FALSE,
        'use_dynamic_delivery' => FALSE,
        'image_style' => '',
        'width' => NULL,
        'height' => NULL,
        'fit' => '',
        'format' => '',
        'quality' => NULL,
      ] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $elements = parent::settingsForm($form, $form_state);

    $current_variant = $this->getSetting('variant');
    $options = ['' => $this->t('- Original (no transformation) -')];
    foreach ($this->assetKiwiClient->getImageStyles() as $style) {
      $key = $style['key'] ?? '';
      if ($key === '') {
        continue;
      }
      $options[$key] = $style['name'] ?? $key;
    }
    if ($current_variant !== '' && !isset($options[$current_variant])) {
      // Preserve the configured value even if it can't currently be fetched
      // from asset.kiwi, so saving the display doesn't silently discard it.
      $options[$current_variant] = $this->t('@key (not found in asset.kiwi)', ['@key' => $current_variant]);
    }

    $elements['variant'] = [
      '#type' => 'select',
      '#title' => $this->t('Image style'),
      '#description' => $this->t('The asset.kiwi image style (variant) to render. Image styles are defined and managed in asset.kiwi.'),
      '#options' => $options,
      '#default_value' => $current_variant,
    ];

    $elements['image_loading'] = [
      '#type' => 'select',
      '#title' => $this->t('Image loading'),
      '#options' => [
        'lazy' => $this->t('Lazy'),
        'eager' => $this->t('Eager'),
      ],
      '#default_value' => $this->getSetting('image_loading'),
    ];

    $elements['link_to_original'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Link to original asset'),
      '#default_value' => $this->getSetting('link_to_original'),
    ];

    // --- DynamicDelivery settings ---
    $elements['dynamic_delivery'] = [
      '#type' => 'details',
      '#title' => $this->t('DynamicDelivery (on-the-fly transforms)'),
      '#description' => $this->t('When enabled, images are transformed on-the-fly by asset.kiwi rather than using pre-computed variants.'),
      '#open' => $this->getSetting('use_dynamic_delivery'),
    ];

    $elements['dynamic_delivery']['use_dynamic_delivery'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use DynamicDelivery'),
      '#description' => $this->t('Generate images on-the-fly via imgproxy. If unchecked, the variant selected above is used instead.'),
      '#default_value' => $this->getSetting('use_dynamic_delivery'),
    ];

    $current_image_style = $this->getSetting('image_style');
    $styleOptions = ['' => $this->t('- Ad-hoc (use width/height below) -')];
    foreach ($this->assetKiwiClient->getImageStyles() as $style) {
      $key = $style['key'] ?? '';
      if ($key === '') {
        continue;
      }
      $styleOptions[$key] = $style['name'] ?? $key;
    }
    if ($current_image_style !== '' && !isset($styleOptions[$current_image_style])) {
      $styleOptions[$current_image_style] = $this->t('@key (not found in asset.kiwi)', ['@key' => $current_image_style]);
    }

    $elements['dynamic_delivery']['image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Image style (DynamicDelivery)'),
      '#description' => $this->t('A named image style preset. Choose "Ad-hoc" to configure dimensions below.'),
      '#options' => $styleOptions,
      '#default_value' => $current_image_style,
    ];

    $elements['dynamic_delivery']['width'] = [
      '#type' => 'number',
      '#title' => $this->t('Width'),
      '#description' => $this->t('Output width in pixels. Leave empty for auto.'),
      '#default_value' => $this->getSetting('width'),
      '#min' => 1,
    ];

    $elements['dynamic_delivery']['height'] = [
      '#type' => 'number',
      '#title' => $this->t('Height'),
      '#description' => $this->t('Output height in pixels. Leave empty for auto.'),
      '#default_value' => $this->getSetting('height'),
      '#min' => 1,
    ];

    $elements['dynamic_delivery']['fit'] = [
      '#type' => 'select',
      '#title' => $this->t('Fit mode'),
      '#description' => $this->t('How the image should fit within the given dimensions.'),
      '#options' => [
        '' => $this->t('- Default -'),
        'fill' => $this->t('Fill (crop to exact dimensions)'),
        'fit' => $this->t('Fit (contain within bounds)'),
        'fill-down' => $this->t('Fill-down (scale down only)'),
        'fit-down' => $this->t('Fit-down (contain, scale down only)'),
        'crop' => $this->t('Crop'),
      ],
      '#default_value' => $this->getSetting('fit'),
    ];

    $elements['dynamic_delivery']['format'] = [
      '#type' => 'select',
      '#title' => $this->t('Output format'),
      '#description' => $this->t('Convert the image to a specific format. Leave empty to preserve the original format.'),
      '#options' => [
        '' => $this->t('- Preserve original -'),
        'webp' => 'WebP',
        'avif' => 'AVIF',
        'jpeg' => 'JPEG',
        'png' => 'PNG',
        'gif' => 'GIF',
      ],
      '#default_value' => $this->getSetting('format'),
    ];

    $elements['dynamic_delivery']['quality'] = [
      '#type' => 'number',
      '#title' => $this->t('Quality'),
      '#description' => $this->t('Output quality (1–100). Leave empty for default.'),
      '#default_value' => $this->getSetting('quality'),
      '#min' => 1,
      '#max' => 100,
    ];

    return $elements;
  }

  public function settingsSummary(): array {
    $summary = [];

    if ($this->getSetting('use_dynamic_delivery')) {
      $imageStyle = $this->getSetting('image_style');
      if (!empty($imageStyle)) {
        $summary[] = $this->t('DynamicDelivery style: @style', ['@style' => $imageStyle]);
      }
      else {
        $dims = [];
        if ($this->getSetting('width')) {
          $dims[] = $this->t('@w px wide', ['@w' => $this->getSetting('width')]);
        }
        if ($this->getSetting('height')) {
          $dims[] = $this->t('@h px tall', ['@h' => $this->getSetting('height')]);
        }
        $label = !empty($dims) ? implode(', ', $dims) : $this->t('original size');
        $summary[] = $this->t('DynamicDelivery: @label', ['@label' => $label]);
      }
    }
    else {
      $variant = $this->getSetting('variant');
      $summary[] = $variant ? $this->t('Variant: @variant', ['@variant' => $variant]) : $this->t('Original image');
    }

    $summary[] = $this->t('Loading: @loading', ['@loading' => $this->getSetting('image_loading')]);
    if ($this->getSetting('link_to_original')) {
      $summary[] = $this->t('Linked to original');
    }
    return $summary;
  }

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $variant = $this->getSetting('variant');
    $loading = $this->getSetting('image_loading');
    $link = $this->getSetting('link_to_original');
    $useDynamicDelivery = $this->getSetting('use_dynamic_delivery');

    foreach ($items as $delta => $item) {
      $uuid = $item->getString();
      if (empty($uuid)) {
        continue;
      }

      $asset = $this->assetKiwiClient->getAsset($uuid);
      if (empty($asset)) {
        continue;
      }

      $data = $this->assetKiwiClient->normalizeAsset($asset);
      $original_url = $data['url'] ?? '';
      $display_url = $original_url;

      if ($useDynamicDelivery) {
        $imageStyleId = $this->getSetting('image_style');
        if (!empty($imageStyleId)) {
          $display_url = $this->assetKiwiClient->getTransformUrlByStyle($uuid, $imageStyleId);
        }
        else {
          $options = [];
          if ($w = $this->getSetting('width')) { $options['w'] = $w; }
          if ($h = $this->getSetting('height')) { $options['h'] = $h; }
          if ($f = $this->getSetting('fit')) { $options['fit'] = $f; }
          if ($fmt = $this->getSetting('format')) { $options['format'] = $fmt; }
          if ($q = $this->getSetting('quality')) { $options['q'] = $q; }
          $display_url = $this->assetKiwiClient->getTransformUrl($uuid, $options);
        }

        $alt = $data['alt_text'] ?? $data['original_name'] ?? '';
        $image = [
          '#theme' => 'image',
          '#uri' => $display_url,
          '#alt' => $alt,
          '#attributes' => [
            'loading' => $loading,
            'class' => ['assetkiwi-image'],
          ],
        ];
        if ($this->getSetting('width')) {
          $image['#width'] = $this->getSetting('width');
        }
        if ($this->getSetting('height')) {
          $image['#height'] = $this->getSetting('height');
        }
      }
      else {
        // Pre-computed variant (current behaviour).
        $resolved = !empty($variant) ? $this->assetKiwiClient->resolveVariant($data, $variant) : NULL;
        if ($resolved) {
          $display_url = $resolved['url'] ?? $display_url;
        }

        $alt = $data['alt_text'] ?? $data['original_name'] ?? '';

        $image = [
          '#theme' => 'image',
          '#uri' => $display_url,
          '#alt' => $alt,
          '#attributes' => [
            'loading' => $loading,
            'class' => ['assetkiwi-image'],
          ],
        ];

        if ($resolved && !empty($resolved['width'])) {
          $image['#width'] = $resolved['width'];
          $image['#height'] = $resolved['height'];
        }
      }

      if ($link && $original_url) {
        $elements[$delta] = [
          '#type' => 'link',
          '#title' => $image,
          '#url' => Url::fromUri($original_url),
          '#attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ];
      }
      else {
        $elements[$delta] = $image;
      }
    }

    return $elements;
  }

}
