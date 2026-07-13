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

    return $elements;
  }

  public function settingsSummary(): array {
    $summary = [];
    $variant = $this->getSetting('variant');
    $summary[] = $variant ? $this->t('Variant: @variant', ['@variant' => $variant]) : $this->t('Original image');
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
