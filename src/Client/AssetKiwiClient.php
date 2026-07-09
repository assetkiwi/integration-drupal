<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Client;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class AssetKiwiClient {

  protected string $apiUrl;
  protected string $apiToken;
  protected ?string $lastError = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected FileSystemInterface $fileSystem,
    protected RequestStack $requestStack,
    protected LoggerInterface $logger,
  ) {
    $config = $this->configFactory->get('assetkiwi_connect.settings');
    $this->apiUrl = rtrim((string)($config->get('api_url') ?? ''), '/');
    $this->apiToken = (string)($config->get('api_token') ?? '');
  }

  /**
   * Re-reads API URL and token from configuration.
   *
   * Useful after config has been updated programmatically.
   */
  public function refreshConfig(): void {
    $config = $this->configFactory->get('assetkiwi_connect.settings');
    $this->apiUrl = rtrim((string)($config->get('api_url') ?? ''), '/');
    $this->apiToken = (string)($config->get('api_token') ?? '');
  }

  public function setCredentials(string $apiUrl, string $apiToken): void {
    $this->apiUrl = $apiUrl;
    $this->apiToken = $apiToken;
  }

  protected function defaultHeaders(): array {
    return [
      'Authorization' => 'Bearer ' . $this->apiToken,
      'Accept' => 'application/json',
    ];
  }

  public function getAssets(array $params = []): array {
    // Fix: asset.kiwi uses 'type' for category filtering (image|video|audio|document|other),
    // not 'mime_type'. The 'document' value in particular maps to multiple MIME types
    // (application/pdf, application/msword, etc.) so passing it as mime_type breaks.
    if (isset($params['mime_type']) && in_array($params['mime_type'], ['image', 'video', 'audio', 'document', 'other'], TRUE)) {
      $params['type'] = $params['mime_type'];
      unset($params['mime_type']);
    }
    try {
      $response = $this->httpClient->request('GET', $this->apiUrl . '/api/v1/assets', [
        'headers' => $this->defaultHeaders(),
        'query' => $params,
      ]);
      return $this->decodeResponse($response);
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      $this->logger->error('Failed to fetch assets: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  public function getAsset(string $uuid): array {
    try {
      $response = $this->httpClient->request('GET', $this->apiUrl . '/api/v1/assets/' . $uuid, [
        'headers' => $this->defaultHeaders(),
      ]);
      return $this->decodeResponse($response);
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      $this->logger->error('Failed to fetch asset @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  public function getCollections(): array {
    try {
      $response = $this->httpClient->request('GET', $this->apiUrl . '/api/v1/collections', [
        'headers' => $this->defaultHeaders(),
      ]);
      return $this->decodeResponse($response);
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      $this->logger->error('Failed to fetch collections: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  public function getTags(): array {
    try {
      $response = $this->httpClient->request('GET', $this->apiUrl . '/api/v1/tags', [
        'headers' => $this->defaultHeaders(),
      ]);
      return $this->decodeResponse($response);
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      $this->logger->error('Failed to fetch tags: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  public function downloadAsset(string $uuid): ?ResponseInterface {
    try {
      return $this->httpClient->request('POST', $this->apiUrl . '/api/v1/assets/' . $uuid . '/download', [
        'headers' => $this->defaultHeaders(),
        'stream' => TRUE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      $this->logger->error('Failed to download asset @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  public function normalizeAsset(array $asset): array {
    return $asset['data'] ?? $asset;
  }

  public function getAssetDisplayName(array $asset): string {
    return $asset['original_name'] ?? $asset['name'] ?? $asset['filename'] ?? 'Untitled';
  }

  public function resolveVariant(array $asset, string $variantName): ?array {
    foreach ($asset['variants'] ?? [] as $variant) {
      if (($variant['variant_name'] ?? '') === $variantName) {
        return $variant;
      }
    }
    return NULL;
  }

  public function resolveVariantUrl(array $asset, string $variantName): ?string {
    return $this->resolveVariant($asset, $variantName)['url'] ?? NULL;
  }

  public function normalizePager(array $response, int $currentPage): array {
    $items = $response['data'] ?? $response;
    // The API may wrap pagination in response['meta'] or response['meta']['meta']
    $meta = $response['meta'] ?? [];
    if (isset($meta['meta']) && is_array($meta['meta'])) {
      $meta = $meta['meta'];
    }
    return [
      'current_page' => $currentPage,
      'total' => $meta['total'] ?? count($items),
      'per_page' => $meta['per_page'] ?? 24,
      'last_page' => $meta['last_page'] ?? 1,
    ];
  }

  public function getVariantUrl(array $asset, string $variantName = 'thumb'): ?string {
    return $this->resolveVariantUrl($asset, $variantName);
  }

  public function cacheThumbnail(string $uuid, string $url): ?string {
    $directory = 'public://assetkiwi_thumbnails';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

    $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'jpg';
    $destination = $directory . '/' . $uuid . '.' . $extension;

    if (file_exists($destination)) {
      $config = $this->configFactory->get('assetkiwi_connect.settings');
      $lifetime = (int)($config->get('cache_lifetime') ?? 3600);
      if ($lifetime > 0 && (time() - filemtime($destination)) < $lifetime) {
        return $destination;
      }
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => $this->defaultHeaders(),
        'timeout' => 10,
      ]);
      $data = (string)$response->getBody();
      if (!empty($data)) {
        $this->fileSystem->saveData($data, $destination, FileSystemInterface::EXISTS_REPLACE);
        return $destination;
      }
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      $this->logger->warning('Failed to cache thumbnail for @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
    return NULL;
  }

  public function reportUsage(string $uuid, string $entityType, string $entityId, ?string $url = NULL): void {
    try {
      $host = $this->requestStack->getCurrentRequest()?->getHost() ?? '';
      $this->httpClient->request('POST', $this->apiUrl . '/api/v1/assets/' . $uuid . '/usage', [
        'headers' => $this->defaultHeaders(),
        'json' => [
          'source_site' => $host,
          'source_entity_type' => $entityType,
          'source_entity_id' => $entityId,
          'source_url' => $url,
        ],
      ]);
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      $this->logger->warning('Failed to report usage for @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  public function removeUsage(string $uuid, string $entityType, string $entityId): void {
    try {
      $host = $this->requestStack->getCurrentRequest()?->getHost() ?? '';
      $this->httpClient->request('DELETE', $this->apiUrl . '/api/v1/assets/' . $uuid . '/usage', [
        'headers' => $this->defaultHeaders(),
        'json' => [
          'source_site' => $host,
          'source_entity_type' => $entityType,
          'source_entity_id' => $entityId,
        ],
      ]);
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      $this->logger->warning('Failed to remove usage for @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Downloads the original asset file and saves it as a Drupal managed file.
   *
   * @return array{uri: string, fid: int}|null
   */
  public function downloadAssetFile(string $uuid, string $destinationDir = 'public://assetkiwi_files'): ?array {
    // 1. Get asset metadata to find the original filename and URL
    $asset = $this->getAsset($uuid);
    if (empty($asset)) {
      return NULL;
    }
    $data = $this->normalizeAsset($asset);

    // 2. Get download URL from the asset
    $downloadUrl = $data['url'] ?? $data['download_url'] ?? NULL;
    if (!$downloadUrl) {
      return NULL;
    }

    // 3. Prepare destination directory
    $this->fileSystem->prepareDirectory($destinationDir, FileSystemInterface::CREATE_DIRECTORY);

    // 4. Get original filename (sanitize)
    $originalName = $this->getAssetDisplayName($data);
    $extension = pathinfo(parse_url($downloadUrl, PHP_URL_PATH) ?: $originalName, PATHINFO_EXTENSION);
    $safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $destination = $destinationDir . '/' . $safeFilename . '_' . substr($uuid, 0, 8) . '.' . ($extension ?: 'bin');

    // 5. Download the file
    try {
      $response = $this->httpClient->request('GET', $downloadUrl, [
        'headers' => $this->defaultHeaders(),
        'timeout' => 30,
      ]);
      $data = (string)$response->getBody();
      if (empty($data)) {
        return NULL;
      }

      // 6. Save as managed file
      $file = \Drupal::service('file.repository')->writeData($data, $destination, FileSystemInterface::EXISTS_REPLACE);
      if ($file) {
        $file->setPermanent();
        $file->save();
        return [
          'fid' => $file->id(),
          'uri' => $file->getFileUri(),
          'url' => $file->createFileUrl(FALSE),
          'filename' => $file->getFilename(),
          'mime' => $file->getMimeType(),
          'size' => $file->getSize(),
        ];
      }
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      $this->logger->warning('Failed to download asset @uuid: @message', ['@uuid' => $uuid, '@message' => $e->getMessage()]);
    }
    return NULL;
  }

  /**
   * Returns the last error message, or NULL if no error occurred.
   */
  public function getLastError(): ?string {
    return $this->lastError;
  }

  protected function decodeResponse(ResponseInterface $response): array {
    $body = (string)$response->getBody();
    $data = json_decode($body, TRUE);
    return is_array($data) ? $data : [];
  }

}
