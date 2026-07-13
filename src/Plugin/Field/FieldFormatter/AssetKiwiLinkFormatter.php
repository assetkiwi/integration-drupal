<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\Utility\MimeIconResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Renders an asset.kiwi asset UUID as a download link with a MIME icon.
 *
 * @FieldFormatter(
 *   id = "assetkiwi_link",
 *   label = @Translation("asset.kiwi File Link"),
 *   description = @Translation("Displays a download link to the asset.kiwi file, with an optional mimetype icon."),
 *   field_types = {
 *     "string",
 *   }
 * )
 */
class AssetKiwiLinkFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  protected AssetKiwiClient $assetKiwiClient;

  protected MimeIconResolver $mimeIconResolver;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->assetKiwiClient = $container->get('assetkiwi_connect.client');
    $instance->mimeIconResolver = $container->get('assetkiwi_connect.mime_icon_resolver');
    return $instance;
  }

  public static function defaultSettings(): array {
    return [
        'link_text_mode' => 'filename',
        'link_text_custom' => '',
        'show_icon' => TRUE,
        'show_size' => FALSE,
      ] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $elements = parent::settingsForm($form, $form_state);

    $elements['link_text_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Link text'),
      '#options' => [
        'filename' => $this->t('Original filename'),
        'download' => $this->t('Fixed text ("Download")'),
        'custom' => $this->t('Custom text'),
      ],
      '#default_value' => $this->getSetting('link_text_mode'),
    ];

    $elements['link_text_custom'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom link text'),
      '#default_value' => $this->getSetting('link_text_custom'),
      '#states' => [
        'visible' => [
          ':input[name*="link_text_mode"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $elements['show_icon'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show mimetype icon'),
      '#default_value' => $this->getSetting('show_icon'),
    ];

    $elements['show_size'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show file size'),
      '#default_value' => $this->getSetting('show_size'),
    ];

    return $elements;
  }

  public function settingsSummary(): array {
    $summary = [];
    $modes = [
      'filename' => $this->t('Link text: original filename'),
      'download' => $this->t('Link text: "Download"'),
      'custom' => $this->t('Link text: custom'),
    ];
    $mode = $this->getSetting('link_text_mode');
    $summary[] = $modes[$mode] ?? $modes['filename'];
    $summary[] = $this->getSetting('show_icon') ? $this->t('Icon shown') : $this->t('Icon hidden');
    $summary[] = $this->getSetting('show_size') ? $this->t('Size shown') : $this->t('Size hidden');
    return $summary;
  }

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $mode = $this->getSetting('link_text_mode');
    $custom = $this->getSetting('link_text_custom');
    $show_icon = $this->getSetting('show_icon');
    $show_size = $this->getSetting('show_size');

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
      $filename = match ($mode) {
        'download' => $this->t('Download'),
        'custom' => $custom !== '' ? $custom : ($data['original_name'] ?? $data['name'] ?? $data['filename'] ?? 'Download'),
        default => $data['original_name'] ?? $data['name'] ?? $data['filename'] ?? 'Download',
      };

      $children = [];
      if ($show_icon) {
        $bucket = $this->mimeIconResolver->getIconBucket($mime);
        $core_class = $this->mimeIconResolver->getCoreIconClass($mime);
        $children['icon'] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => [
            'class' => ['assetkiwi-file-icon', 'assetkiwi-file-icon--' . $bucket, 'file--' . $core_class],
            'aria-hidden' => 'true',
          ],
        ];
      }

      $children['link'] = [
        '#type' => 'link',
        '#title' => $filename,
        '#url' => Url::fromUri($url),
        '#attributes' => ['target' => '_blank', 'rel' => 'noopener'],
      ];

      if ($show_size && !empty($data['size'])) {
        $children['size'] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['assetkiwi-file-size']],
          '#value' => ' (' . $this->formatBytes((int) $data['size']) . ')',
        ];
      }

      $elements[$delta] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['assetkiwi-file-link']],
        '#attached' => ['library' => ['assetkiwi_connect/file_icons']],
      ] + $children;
    }

    return $elements;
  }

  /** Formats bytes to a human-readable string. */
  private function formatBytes(int $bytes): string {
    if ($bytes < 1024) {
      return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB'];
    $value = $bytes / 1024;
    $unit = 'KB';
    foreach ($units as $unit) {
      if ($value < 1024) {
        break;
      }
      $value /= 1024;
    }
    return number_format($value, 1) . ' ' . $unit;
  }

}
