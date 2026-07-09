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
 * Renders an asset.kiwi asset UUID as an HTML5 video player.
 *
 * @FieldFormatter(
 *   id = "assetkiwi_video",
 *   label = @Translation("asset.kiwi Video"),
 *   field_types = {
 *     "string",
 *   }
 * )
 */
class AssetKiwiVideoFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  protected AssetKiwiClient $assetKiwiClient;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->assetKiwiClient = $container->get('assetkiwi_connect.client');
    return $instance;
  }

  public static function defaultSettings(): array {
    return [
        'controls' => TRUE,
        'autoplay' => FALSE,
        'muted' => FALSE,
        'loop' => FALSE,
        'use_poster' => TRUE,
      ] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $elements = parent::settingsForm($form, $form_state);

    $elements['controls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show player controls'),
      '#default_value' => $this->getSetting('controls'),
    ];

    $elements['autoplay'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Autoplay'),
      '#default_value' => $this->getSetting('autoplay'),
    ];

    $elements['muted'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Muted'),
      '#default_value' => $this->getSetting('muted'),
    ];

    $elements['loop'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Loop'),
      '#default_value' => $this->getSetting('loop'),
    ];

    $elements['use_poster'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use preview image as poster'),
      '#default_value' => $this->getSetting('use_poster'),
    ];

    return $elements;
  }

  public function settingsSummary(): array {
    $summary = [];
    $summary[] = $this->getSetting('controls') ? $this->t('Controls shown') : $this->t('Controls hidden');
    $summary[] = $this->getSetting('autoplay') ? $this->t('Autoplay on') : $this->t('Autoplay off');
    $summary[] = $this->getSetting('muted') ? $this->t('Muted') : $this->t('Not muted');
    $summary[] = $this->getSetting('loop') ? $this->t('Loop on') : $this->t('Loop off');
    $summary[] = $this->getSetting('use_poster') ? $this->t('Poster from preview') : $this->t('No poster');
    return $summary;
  }

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $controls = $this->getSetting('controls');
    $autoplay = $this->getSetting('autoplay');
    $muted = $this->getSetting('muted');
    $loop = $this->getSetting('loop');
    $use_poster = $this->getSetting('use_poster');

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
      $url = $data['url'] ?? $data['download_url'] ?? NULL;
      if (!$url) {
        continue;
      }

      $mime = $data['mime_type'] ?? $data['mimeType'] ?? '';
      if (!str_starts_with($mime, 'video/')) {
        // Not a video — render a download link instead.
        $filename = $data['original_name'] ?? $data['name'] ?? $data['filename'] ?? 'Download';
        $elements[$delta] = [
          '#type' => 'link',
          '#title' => $filename,
          '#url' => Url::fromUri($url),
          '#attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ];
        continue;
      }

      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'video',
        '#attributes' => array_filter([
          'src' => $url,
          'controls' => $controls ? 'controls' : NULL,
          'autoplay' => $autoplay ? 'autoplay' : NULL,
          'muted' => $muted ? 'muted' : NULL,
          'loop' => $loop ? 'loop' : NULL,
          'poster' => $use_poster ? ($this->assetKiwiClient->resolveVariantUrl($data, 'preview') ?: NULL) : NULL,
          'class' => ['assetkiwi-video'],
        ], static fn ($value): bool => $value !== NULL),
      ];
    }

    return $elements;
  }

}
