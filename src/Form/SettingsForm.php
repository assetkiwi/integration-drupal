<?php

/**
 * @file
 * asset.kiwi Settings form.
 */

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the asset.kiwi connection settings form.
 */
class SettingsForm extends ConfigFormBase {

  protected AssetKiwiClient $assetKiwiClient;

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

    $form['serve_from_cdn'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Serve assets directly from asset.kiwi'),
      '#description' => $this->t('Reference asset.kiwi URLs directly instead of downloading files locally. Disable if you need local copies for further processing.'),
      '#default_value' => $config->get('serve_from_cdn') ?? TRUE,
    ];

    // --- OAuth2 Configuration (Per-User Mode) ---
    $form['oauth'] = [
      '#type' => 'details',
      '#title' => $this->t('OAuth2 Authentication'),
      '#description' => $this->t('Configure per-user OAuth2 authentication. When enabled, each Drupal user authenticates individually against the asset.kiwi authorization server instead of sharing a single API token. The authorization and token endpoints are derived automatically from the API Base URL above.'),
      '#open' => $config->get('oauth_mode') === 'per_user',
    ];

    $form['oauth']['oauth_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Authentication Mode'),
      '#options' => [
        'shared_token' => $this->t('Shared token — all users share the API token configured above'),
        'per_user' => $this->t('Per-user OAuth — each user gets their own DAM identity'),
      ],
      '#default_value' => $config->get('oauth_mode') ?? 'shared_token',
    ];

    $redirectUri = Url::fromRoute('assetkiwi_connect.oauth.callback', [], ['absolute' => TRUE])->toString();

    $form['oauth']['oauth_redirect_uri'] = [
      '#type' => 'item',
      '#title' => $this->t('Redirect URI'),
      '#markup' => '<code>' . Html::escape($redirectUri) . '</code>',
      '#description' => $this->t('When creating the OAuth client on your asset.kiwi instance (Settings → OAuth Clients), register this exact URL as a Redirect URI.'),
      '#states' => [
        'visible' => [
          ':input[name="oauth_mode"]' => ['value' => 'per_user'],
        ],
      ],
    ];

    $form['oauth']['oauth_client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth Client ID'),
      '#description' => $this->t('The client ID registered with the asset.kiwi OAuth2 authorization server.'),
      '#default_value' => $config->get('oauth_client_id') ?? '',
      '#maxlength' => 255,
      '#states' => [
        'visible' => [
          ':input[name="oauth_mode"]' => ['value' => 'per_user'],
        ],
      ],
    ];

    $form['oauth']['oauth_client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('OAuth Client Secret'),
      '#description' => $this->t('The client secret for the registered OAuth2 application.'),
      '#default_value' => $config->get('oauth_client_secret') ?? '',
      '#maxlength' => 255,
      '#states' => [
        'visible' => [
          ':input[name="oauth_mode"]' => ['value' => 'per_user'],
        ],
      ],
    ];

    $form['oauth']['oauth_scopes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('OAuth Scopes'),
      '#description' => $this->t('The scopes to request during authorization. At minimum, assets:read is required for browsing and importing assets. collections:read and tags:read are required for the collection/tag filter dropdowns in the asset picker to load — without them, the picker still browses assets but those filters stay empty.'),
      '#options' => [
        'assets:read' => $this->t('assets:read — Browse and import assets'),
        'assets:write' => $this->t('assets:write — Upload and modify assets'),
        'collections:read' => $this->t('collections:read — Browse collections (picker filter dropdown)'),
        'tags:read' => $this->t('tags:read — Browse tags (picker filter dropdown)'),
      ],
      '#default_value' => $config->get('oauth_scopes') ?: ['assets:read', 'collections:read', 'tags:read'],
      '#states' => [
        'visible' => [
          ':input[name="oauth_mode"]' => ['value' => 'per_user'],
        ],
      ],
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
      \Drupal::service('file_system'),
      \Drupal::service('request_stack'),
      \Drupal::service('logger.channel.assetkiwi_connect'),
      NULL,
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
    $config = $this->config('assetkiwi_connect.settings');
    $config
      ->set('api_url', $form_state->getValue('api_url'))
      ->set('api_token', $form_state->getValue('api_token'))
      ->set('cache_lifetime', (int)$form_state->getValue('cache_lifetime'))
      ->set('webhook_secret', $form_state->getValue('webhook_secret'))
      ->set('serve_from_cdn', (bool)$form_state->getValue('serve_from_cdn'))
      ->set('oauth_mode', $form_state->getValue('oauth_mode'))
      ->set('oauth_client_id', $form_state->getValue('oauth_client_id'))
      ->set('oauth_scopes', array_values(array_filter($form_state->getValue('oauth_scopes') ?: [])))
      ->set('image_style_mapping', [
        'thumbnail' => $form_state->getValue('mapping_thumbnail'),
        'medium' => $form_state->getValue('mapping_medium'),
        'large' => $form_state->getValue('mapping_large'),
      ]);

    // Only overwrite the secret if a new value was provided (password fields
    // don't retain the stored value in the UI).
    $newSecret = $form_state->getValue('oauth_client_secret');
    if ($newSecret !== NULL && $newSecret !== '') {
      $config->set('oauth_client_secret', $newSecret);
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
