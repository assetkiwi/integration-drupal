<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AssetBrowserController extends ControllerBase {

  protected AssetKiwiClient $assetKiwiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->assetKiwiClient = $container->get('assetkiwi_connect.client');
    return $instance;
  }

  public function browse(Request $request): array|Response {
    // Parse the allowed_types restriction passed from the widget/media type.
    $allowed_types_param = $request->query->get('allowed_types', '');
    $allowed_types = array_filter(array_map('trim', explode(',', $allowed_types_param)));

    $user_mime_type = $request->query->get('mime_type', '');

    // Determine the effective type filter as the intersection of what the
    // user has chosen and what the media type configuration permits.
    $effective_mime_type = $user_mime_type;
    if (!empty($allowed_types) && !empty($user_mime_type)) {
      if (!in_array($user_mime_type, $allowed_types, TRUE)) {
        $effective_mime_type = '';
      }
    }
    elseif (!empty($allowed_types) && empty($user_mime_type) && count($allowed_types) === 1) {
      // Single allowed type — apply automatically.
      $effective_mime_type = reset($allowed_types);
    }

    $cardinality = (int)$request->query->get('cardinality', 1);

    $filters = [
      'search' => $request->query->get('search', ''),
      'type' => $effective_mime_type,
      'collection' => $request->query->get('collection', ''),
      'tag' => $request->query->get('tag', ''),
      'page' => max(1, (int)$request->query->get('page', 1)),
      'mode' => $request->query->get('mode', ''),
      'per_page' => min(100, max(1, (int)$request->query->get('per_page', 24))),
    ];

    $assets = $this->assetKiwiClient->getAssets(array_filter($filters));
    $apiError = $this->assetKiwiClient->getLastError();
    $collections = $this->assetKiwiClient->getCollections();
    $tags = $this->assetKiwiClient->getTags();

    $pager = $this->assetKiwiClient->normalizePager($assets, $filters['page']);
    $items = $assets['data'] ?? $assets;

    // Build the type options for the template, restricted to allowed types
    // when a subset is configured.
    $all_type_options = [
      'image' => $this->t('Images'),
      'video' => $this->t('Video'),
      'audio' => $this->t('Audio'),
      'document' => $this->t('Documents'),
    ];

    if (!empty($allowed_types)) {
      $type_options = array_intersect_key($all_type_options, array_flip($allowed_types));
    }
    else {
      $type_options = $all_type_options;
    }

    // Re-expose user_mime_type (not the coerced value) so the template can
    // reflect what the user actually selected in the dropdown.
    $filters['type'] = $user_mime_type;

    $build = [
      '#theme' => 'assetkiwi_asset_browser',
      '#assets' => $items,
      '#collections' => $collections['data'] ?? $collections,
      '#tags' => $tags['data'] ?? $tags,
      '#filters' => $filters,
      '#pager' => $pager,
      '#type_options' => $type_options,
      '#show_type_filter' => count($allowed_types) !== 1,
      '#allowed_types' => $allowed_types,
      '#api_error' => $apiError,
      '#cardinality' => $cardinality,
      '#cache' => ['max-age' => 0],
      '#attached' => [
        'library' => ['assetkiwi_connect/browser', 'assetkiwi_connect/widget'],
      ],
    ];

    // When loaded in a modal dialog, return bare rendered HTML.
    if ($request->query->get('modal')) {
      $renderer = \Drupal::service('renderer');
      $html = (string)$renderer->renderRoot($build);
      return new Response($html);
    }

    return $build;
  }

  public function select(string $uuid): JsonResponse {
    $asset = $this->assetKiwiClient->getAsset($uuid);

    if (empty($asset)) {
      return new JsonResponse(['error' => $this->t('Asset not found.')->render()], 404);
    }

    return new JsonResponse($asset);
  }

  public function selectMultiple(Request $request): JsonResponse {
    $uuids = $request->request->all('uuids');
    if (empty($uuids)) {
      return new JsonResponse(['error' => $this->t('No assets selected.')->render()], 400);
    }

    $assets = [];
    foreach ($uuids as $uuid) {
      $asset = $this->assetKiwiClient->getAsset($uuid);
      if (!empty($asset)) {
        $data = $this->assetKiwiClient->normalizeAsset($asset);
        $assets[] = $data;
      }
    }

    return new JsonResponse(['assets' => $assets]);
  }

}
