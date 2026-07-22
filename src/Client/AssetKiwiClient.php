<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Client;

use Drupal\assetkiwi_connect\OAuth\OAuthManager;
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
  protected ?int $lastStatusCode = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected FileSystemInterface $fileSystem,
    protected RequestStack $requestStack,
    protected LoggerInterface $logger,
    protected ?OAuthManager $oauthManager = NULL,
  ) {
    $config = $this->configFactory->get('assetkiwi_connect.settings');
    $this->apiUrl = rtrim((string)($config->get('api_url') ?? ''), '/');
    $this->apiToken = (string)($config->get('api_token') ?? '');
  }

  /**
   * Re-reads API URL and token from configuration.
   */
  public function refreshConfig(): void {
    $config = $this->configFactory->get('assetkiwi_connect.settings');
    $this->apiUrl = rtrim((string)($config->get('api_url') ?? ''), '/');
    $this->apiToken = (string)($config->get('api_token') ?? '');
  }

  /**
   * Resolves the API token to use for the current request.
   *
   * In shared-token mode the configured api_token is used for all users.
   * In per-user OAuth mode, an authenticated user MUST have their own
   * personal access token — falling back to the shared token here would
   * silently bypass the "each user connects individually" requirement the
   * admin explicitly opted into by enabling per-user mode, and the picker
   * would never prompt the user to connect. Only truly anonymous requests
   * (no current user at all) fall back to the shared token.
   */
  protected function resolveApiToken(): ?string {
    if (!$this->oauthManager || !$this->oauthManager->isEnabled()) {
      return $this->apiToken;
    }

    $uid = \Drupal::currentUser()->id();
    if ($uid) {
      return $this->oauthManager->getToken((int) $uid);
    }

    return $this->apiToken;
  }

  public function setCredentials(string $apiUrl, string $apiToken): void {
    $this->apiUrl = $apiUrl;
    $this->apiToken = $apiToken;
  }

  /**
   * Sends a request and decodes the JSON response.
   */
  private function request(string $method, string $path, array $options = []): array|null {
    try {
      $response = $this->httpClient->request($method, $this->apiUrl . $path, $options);
      $this->lastStatusCode = $response->getStatusCode();
      $body = (string)$response->getBody();
      $data = json_decode($body, TRUE);
      return is_array($data) ? $data : NULL;
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      // Capture the HTTP status from the response if available.
      if (method_exists($e, 'getResponse') && $e->getResponse() !== NULL) {
        $this->lastStatusCode = $e->getResponse()->getStatusCode();
      }
      else {
        $this->lastStatusCode = $e->getCode() ?: NULL;
      }
      return NULL;
    }
  }

  protected function defaultHeaders(): array {
    return [
      'Authorization' => 'Bearer ' . ($this->resolveApiToken() ?? ''),
      'Accept' => 'application/json',
    ];
  }

  public function getAssets(array $params = []): array {
    // Map 'mime_type' to the API's 'type' param for category filtering.
    if (isset($params['mime_type']) && in_array($params['mime_type'], ['image', 'video', 'audio', 'document', 'other'], TRUE)) {
      $params['type'] = $params['mime_type'];
      unset($params['mime_type']);
    }
    $result = $this->request('GET', '/api/v1/assets', ['headers' => $this->defaultHeaders(), 'query' => $params]);
    return is_array($result) ? $result : [];
  }

  public function getAsset(string $uuid): array {
    $result = $this->request('GET', '/api/v1/assets/' . $uuid, ['headers' => $this->defaultHeaders()]);
    return is_array($result) ? $result : [];
  }

  public function getCollections(): array {
    $result = $this->request('GET', '/api/v1/collections', ['headers' => $this->defaultHeaders()]);
    return is_array($result) ? $result : [];
  }

  public function getTags(): array {
    $result = $this->request('GET', '/api/v1/tags', ['headers' => $this->defaultHeaders()]);
    return is_array($result) ? $result : [];
  }

  /**
   * Returns the image styles (variant presets) defined in asset.kiwi.
   *
   * @return array
   */
  public function getImageStyles(): array {
    $result = $this->request('GET', '/api/v1/image-styles', ['headers' => $this->defaultHeaders()]);
    return is_array($result) ? $result : [];
  }

  /**
   * Whether to reference assets from the CDN instead of storing them locally.
   */
  public function shouldServeFromCdn(): bool {
    $config = $this->configFactory->get('assetkiwi_connect.settings');
    $value = $config->get('serve_from_cdn');
    return $value === NULL ? TRUE : (bool)$value;
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

  /**
   * Uploads a local file to asset.kiwi.
   *
   * **@internal — for the migration submodule only.** End-user uploads must
   * happen through the asset.kiwi app directly. This method exists solely
   * for the `assetkiwi_connect_migrate` bulk offload pipeline.
   *
   * Relies on asset.kiwi's own content-hash dedup: a 409 response means an
   * asset with identical bytes already exists there, and its UUID is reused
   * rather than creating a duplicate — this is what makes re-running a
   * migration safe.
   *
   * @return array{uuid: string, reused: bool}|null
   *   NULL on failure — see getLastError().
   *
   * @internal
   */
  public function uploadAsset(string $filePath, string $originalFilename, string $mimeType): ?array {
    if (!is_readable($filePath)) {
      $this->lastError = 'File not readable: ' . $filePath;
      return NULL;
    }

    $handle = fopen($filePath, 'r');
    if ($handle === FALSE) {
      $this->lastError = 'Could not open file: ' . $filePath;
      return NULL;
    }

    try {
      $response = $this->httpClient->request('POST', $this->apiUrl . '/api/v1/assets', [
        'headers' => [
          'Authorization' => 'Bearer ' . ($this->resolveApiToken() ?? ''),
          'Accept' => 'application/json',
        ],
        'multipart' => [
          [
            'name' => 'file',
            'contents' => $handle,
            'filename' => $originalFilename,
            'headers' => ['Content-Type' => $mimeType],
          ],
        ],
        // Read the body ourselves below — a 409 (dedup) is an expected,
        // meaningful response here, not an error to throw on.
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      $this->logger->error('Failed to upload asset @filename: @message', [
        '@filename' => $originalFilename,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    $status = $response->getStatusCode();
    $body = json_decode((string)$response->getBody(), TRUE) ?? [];

    if ($status === 201) {
      $uuid = $body['data']['uuid'] ?? $body['uuid'] ?? NULL;
      if (!$uuid) {
        $this->lastError = 'Upload succeeded but no UUID was returned.';
        return NULL;
      }
      return ['uuid' => $uuid, 'reused' => FALSE];
    }

    if ($status === 409) {
      $uuid = $body['existing_uuid'] ?? NULL;
      if (!$uuid) {
        $this->lastError = 'Duplicate reported but no existing_uuid was returned.';
        return NULL;
      }
      return ['uuid' => $uuid, 'reused' => TRUE];
    }

    $this->lastError = $body['message'] ?? ('Upload failed with HTTP ' . $status);
    $this->logger->error('Failed to upload asset @filename: @message', [
      '@filename' => $originalFilename,
      '@message' => $this->lastError,
    ]);
    return NULL;
  }

  /**
   * Get a DynamicDelivery transform URL for on-the-fly image processing.
   *
   * Generates a URL that 302-redirects to a signed imgproxy URL.
   *
   * @param string $uuid Asset UUID
   * @param array $options Transform options: w, h, fit, format, q, gravity
   * @return string The API transform URL
   */
  public function getTransformUrl(string $uuid, array $options = []): string {
    $params = [];
    if (isset($options['w'])) { $params['w'] = (int) $options['w']; }
    if (isset($options['h'])) { $params['h'] = (int) $options['h']; }
    if (isset($options['fit'])) { $params['fit'] = $options['fit']; }
    if (isset($options['format'])) { $params['format'] = $options['format']; }
    if (isset($options['q'])) { $params['q'] = (int) $options['q']; }
    if (isset($options['gravity'])) { $params['gravity'] = $options['gravity']; }

    $query = !empty($params) ? '?' . http_build_query($params) : '';
    return $this->apiUrl . '/api/v1/media/' . $uuid . '/transform' . $query;
  }

  /**
   * Get a DynamicDelivery transform URL using a named Image Style preset.
   *
   * @param string $uuid Asset UUID
   * @param string $imageStyleId The image style key registered in asset.kiwi
   * @return string The API transform URL for the named style
   */
  public function getTransformUrlByStyle(string $uuid, string $imageStyleId): string {
    return $this->apiUrl . '/api/v1/media/' . $uuid . '/transform/' . $imageStyleId;
  }

  public function normalizeAsset(array $asset): array {
    return $asset['data'] ?? $asset;
  }

  /**
   * Normalizes an asset record into the flat shape the picker widget consumes.
   *
   * Consolidates the field-fallback logic that used to be duplicated across
   * the Twig browser template, AssetKiwiAddForm, and AssetBrowserController.
   *
   * @return array
   */
  public function toPickerAsset(array $asset): array {
    $data = $this->normalizeAsset($asset);

    $mime = $data['mime_type'] ?? $data['mimeType'] ?? '';
    $url = $data['url'] ?? $data['download_url'] ?? NULL;

    // Fall back to original for images only — non-image blobs aren't usable as <img> src.
    $thumbUrl = $this->resolveVariantUrl($data, 'thumb')
      ?? $this->resolveVariantUrl($data, 'preview');
    if ($thumbUrl === NULL && str_starts_with($mime, 'image/')) {
      $thumbUrl = $url;
    }

    return [
      'uuid' => $data['uuid'] ?? $data['id'] ?? '',
      'name' => $this->getAssetDisplayName($data),
      'mime' => $mime,
      'size' => $data['size'] ?? $data['file_size'] ?? NULL,
      'width' => $data['width'] ?? NULL,
      'height' => $data['height'] ?? NULL,
      'thumbUrl' => $thumbUrl,
      'url' => $url,
    ];
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
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->lastError = 'Could not prepare thumbnail directory: ' . $directory;
      $this->logger->warning('Could not prepare thumbnail directory @dir for asset @uuid.', [
        '@dir' => $directory,
        '@uuid' => $uuid,
      ]);
      return NULL;
    }

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
        'timeout' => 10,
      ]);
      $data = (string)$response->getBody();
      if ($data === '') {
        $this->lastError = 'Empty thumbnail response for asset ' . $uuid;
        $this->logger->warning('Empty thumbnail response for asset @uuid from @url.', [
          '@uuid' => $uuid,
          '@url' => $url,
        ]);
        return NULL;
      }
      $this->fileSystem->saveData($data, $destination, FileSystemInterface::EXISTS_REPLACE);
      return $destination;
    }
    catch (GuzzleException $e) {
      $this->lastError = $e->getMessage();
      $this->logger->warning('Failed to cache thumbnail for @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
    catch (\Throwable $e) {
      $this->lastError = $e->getMessage();
      $this->logger->warning('Unexpected error caching thumbnail for @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
    return NULL;
  }

  public function reportUsage(string $uuid, string $entityType, string $entityId, ?string $url = NULL): void {
    $host = $this->requestStack->getCurrentRequest()?->getHost() ?? '';
    try {
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
    $host = $this->requestStack->getCurrentRequest()?->getHost() ?? '';
    try {
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
    // Download and store the original file from asset.kiwi.
    $asset = $this->getAsset($uuid);
    if (empty($asset)) {
      return NULL;
    }
    $data = $this->normalizeAsset($asset);

    $downloadUrl = $data['url'] ?? $data['download_url'] ?? NULL;
    if (!$downloadUrl) {
      return NULL;
    }

    $this->fileSystem->prepareDirectory($destinationDir, FileSystemInterface::CREATE_DIRECTORY);

    $originalName = $this->getAssetDisplayName($data);
    $extension = pathinfo(parse_url($downloadUrl, PHP_URL_PATH) ?: $originalName, PATHINFO_EXTENSION);
    $safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $destination = $destinationDir . '/' . $safeFilename . '_' . substr($uuid, 0, 8) . '.' . ($extension ?: 'bin');

    try {
      $response = $this->httpClient->request('GET', $downloadUrl, ['timeout' => 30]);
      $data = (string)$response->getBody();
      if (empty($data)) {
        return NULL;
      }

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

  public function getLastError(): ?string {
    return $this->lastError;
  }

  /**
   * Check if the last API error was an authentication failure (401/403).
   */
  public function isLastErrorAuth(): bool {
    if ($this->lastStatusCode !== NULL) {
      return $this->lastStatusCode === 401 || $this->lastStatusCode === 403;
    }
    if (!$this->lastError) {
      return FALSE;
    }
    return str_contains($this->lastError, '401') || str_contains($this->lastError, '403');
  }

  /**
   * Returns the HTTP status code from the most recent request.
   */
  public function getLastStatusCode(): ?int {
    return $this->lastStatusCode;
  }

  protected function decodeResponse(ResponseInterface $response): array {
    $body = (string)$response->getBody();
    $data = json_decode($body, TRUE);
    return is_array($data) ? $data : [];
  }

}
