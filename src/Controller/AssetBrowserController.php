<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\ExistingMediaResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class AssetBrowserController extends ControllerBase {

  protected AssetKiwiClient $assetKiwiClient;

  protected ExistingMediaResolver $existingMediaResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->assetKiwiClient = $container->get('assetkiwi_connect.client');
    $instance->existingMediaResolver = $container->get('assetkiwi_connect.existing_media_resolver');
    return $instance;
  }

  /**
   * Returns a filtered, paginated, JSON-normalized list of assets.
   *
   * Backs the asset.kiwi picker widget (js/assetkiwi-picker.js). The API
   * token stays server-side here — the browser never talks to asset.kiwi
   * directly.
   */
  public function assets(Request $request): JsonResponse {
    $allowed_types_param = $request->query->get('allowed_types', '');
    $allowed_types = array_filter(array_map('trim', explode(',', $allowed_types_param)));

    $user_type = $request->query->get('type', $request->query->get('mime_type', ''));

    // Intersection of user choice and media type configuration.
    $effective_type = $user_type;
    if (!empty($allowed_types) && !empty($user_type)) {
      if (!in_array($user_type, $allowed_types, TRUE)) {
        $effective_type = '';
      }
    }
    elseif (!empty($allowed_types) && empty($user_type) && count($allowed_types) === 1) {
      $effective_type = reset($allowed_types);
    }

    $page = max(1, (int)$request->query->get('page', 1));

    $filters = [
      'search' => $request->query->get('search', ''),
      'type' => $effective_type,
      'collection' => $request->query->get('collection', ''),
      'tag' => $request->query->get('tag', ''),
      'page' => $page,
      'mode' => $request->query->get('mode', ''),
      'per_page' => min(100, max(1, (int)$request->query->get('per_page', 24))),
    ];

    $response = $this->assetKiwiClient->getAssets(array_filter($filters));
    $apiError = $this->assetKiwiClient->getLastError();

    if ($apiError) {
      if ($this->assetKiwiClient->isLastErrorAuth()) {
        $oauthManager = \Drupal::service('assetkiwi_connect.oauth_manager');
        return new JsonResponse([
          'error' => 'oauth_required',
          'message' => $this->t('Your asset.kiwi account is not connected. Connect now to browse your assets.'),
          'authorize_url' => $oauthManager->getAuthorizationUrl($request->headers->get('referer')),
          'meta' => [],
        ], 401);
      }
      return new JsonResponse(['error' => $apiError, 'meta' => []], 502);
    }

    $items = $response['data'] ?? $response;
    $pager = $this->assetKiwiClient->normalizePager($response, $page);

    $data = array_values(array_map(
      fn(array $asset): array => $this->assetKiwiClient->toPickerAsset($asset) + ['existingMediaId' => NULL],
      $items,
    ));

    // Dedup against local media entities when browsing for a specific bundle.
    $bundle = $request->query->get('bundle', '');
    if ($bundle !== '') {
      $media_type = $this->entityTypeManager()->getStorage('media_type')->load($bundle);
      if ($media_type) {
        $existing = $this->existingMediaResolver->findExisting($media_type, array_column($data, 'uuid'));
        foreach ($data as &$asset) {
          $asset['existingMediaId'] = $existing[$asset['uuid']] ?? NULL;
        }
        unset($asset);
      }
    }

    return new JsonResponse([
      'data' => $data,
      'meta' => $pager,
    ]);
  }

  /**
   * Returns collections and tags for the picker widget's filter dropdowns.
   */
  public function facets(Request $request): JsonResponse {
    $collections = $this->assetKiwiClient->getCollections();
    $tags = $this->assetKiwiClient->getTags();
    $apiError = $this->assetKiwiClient->getLastError();

    if ($apiError) {
      if ($this->assetKiwiClient->isLastErrorAuth()) {
        $oauthManager = \Drupal::service('assetkiwi_connect.oauth_manager');
        return new JsonResponse([
          'error' => 'oauth_required',
          'message' => $this->t('Your asset.kiwi account is not connected. Connect now to browse your assets.'),
          'authorize_url' => $oauthManager->getAuthorizationUrl($request->headers->get('referer')),
        ], 401);
      }
      return new JsonResponse(['error' => $apiError], 502);
    }

    // API filters by slug, so use slug as the dropdown value.
    $normalize = static fn(array $item) => [
      'id' => (string)($item['slug'] ?? $item['id'] ?? ''),
      'name' => (string)($item['name'] ?? ''),
    ];

    return new JsonResponse([
      'collections' => array_values(array_map($normalize, $collections['data'] ?? $collections)),
      'tags' => array_values(array_map($normalize, $tags['data'] ?? $tags)),
    ]);
  }

}
