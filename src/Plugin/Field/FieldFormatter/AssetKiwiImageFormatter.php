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
 *   description = @Translation("Displays the image from asset.kiwi.using a configured variant."),
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

    $elements['variant'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Variant name'),
      '#description' => $this->t('The asset.kiwi variant/image style key to use (e.g. thumb, medium, large). Leave empty for original.'),
      '#default_value' => $this->getSetting('variant'),
      '#size' => 30,
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
