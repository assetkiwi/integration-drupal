<?php

/**
 * @file
 * asset.kiwi Settings form.
 */

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the asset.kiwi connection settings form.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The asset.kiwi API client.
   */
  protected AssetKiwiClient $assetKiwiClient;

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->assetKiwiClient = $container->get('assetkiwi_connect.client');
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'assetkiwi_connect_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['assetkiwi_connect.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('assetkiwi_connect.settings');

    $form['api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Base URL'),
      '#description' => $this->t('The base URL of your asset.kiwi instance (e.g. https://assetkiwi.ddev.site).'),
      '#default_value' => $config->get('api_url') ?? '',
      '#required' => TRUE,
      '#maxlength' => 512,
    ];

    $form['api_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Token'),
      '#description' => $this->t('API token from the asset.kiwi Settings page.'),
      '#default_value' => $config->get('api_token') ?? '',
      '#required' => TRUE,
      '#maxlength' => 512,
    ];

    $form['cache_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache Lifetime'),
      '#description' => $this->t('How long to cache asset metadata in seconds. Set to 0 to disable caching.'),
      '#default_value' => $config->get('cache_lifetime') ?? 3600,
      '#required' => TRUE,
      '#min' => 0,
    ];

    $form['webhook_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook Secret'),
      '#description' => $this->t('Shared secret for verifying incoming webhooks from asset.kiwi. Must match the secret configured in asset.kiwi webhook settings.'),
      '#default_value' => $config->get('webhook_secret') ?? '',
      '#maxlength' => 255,
    ];

    $form['image_style_mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Image Style Mapping'),
      '#description' => $this->t('Map Drupal image styles to asset.kiwi variant names. When rendering images, the module will use the mapped variant URL instead of processing locally.'),
      '#open' => FALSE,
    ];

    $form['image_style_mapping']['mapping_thumbnail'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Thumbnail'),
      '#default_value' => $config->get('image_style_mapping.thumbnail') ?? 'thumb',
      '#size' => 30,
    ];

    $form['image_style_mapping']['mapping_medium'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Medium'),
      '#default_value' => $config->get('image_style_mapping.medium') ?? 'medium',
      '#size' => 30,
    ];

    $form['image_style_mapping']['mapping_large'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Large'),
      '#default_value' => $config->get('image_style_mapping.large') ?? 'large',
      '#size' => 30,
    ];

    $form['actions']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Connection'),
      '#submit' => ['::testConnection'],
      '#limit_validation_errors' => [],
      '#weight' => 5,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function testConnection(array &$form, FormStateInterface $form_state): void {
    $api_url = $form_state->getValue('api_url') ?: $this->config('assetkiwi_connect.settings')->get('api_url');
    $api_token = $form_state->getValue('api_token') ?: $this->config('assetkiwi_connect.settings')->get('api_token');

    if (empty($api_url) || empty($api_token)) {
      $this->messenger()->addError($this->t('Please provide both an API URL and API Token before testing the connection.'));
      return;
    }

    $tempClient = new AssetKiwiClient(
      $this->configFactory,
      $this->httpClient,
      \Drupal::service('logger.factory'),
    );
    $tempClient->setCredentials(rtrim($api_url, '/'), $api_token);
    $result = $tempClient->getAssets(['page' => 1]);

    if (!empty($result)) {
      $this->messenger()->addStatus($this->t('Connection successful! asset.kiwi API responded with data.'));
    }
    else {
      $this->messenger()->addWarning($this->t('Connection test returned an empty response. Check your URL and token, or review the recent log messages.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('assetkiwi_connect.settings')
      ->set('api_url', $form_state->getValue('api_url'))
      ->set('api_token', $form_state->getValue('api_token'))
      ->set('cache_lifetime', (int)$form_state->getValue('cache_lifetime'))
      ->set('webhook_secret', $form_state->getValue('webhook_secret'))
      ->set('image_style_mapping', [
        'thumbnail' => $form_state->getValue('mapping_thumbnail'),
        'medium' => $form_state->getValue('mapping_medium'),
        'large' => $form_state->getValue('mapping_large'),
      ])
      ->save();

    parent::submitForm($form, $form_state);
  }

}
