<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class AssetKiwiClientTest extends UnitTestCase {

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected LoggerChannelInterface $logger;
  protected FileSystemInterface $fileSystem;
  protected RequestStack $requestStack;
  protected AssetKiwiClient $client;

  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['api_url', 'https://dam.example.com'],
      ['api_token', 'test-token-123'],
    ]);

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('assetkiwi_connect.settings')
      ->willReturn($config);

    $this->httpClient = $this->createMock(ClientInterface::class);

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->method('get')->willReturn($this->logger);

    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $this->requestStack = new RequestStack();

    $this->client = new AssetKiwiClient(
      $this->configFactory,
      $this->httpClient,
      $this->loggerFactory,
      $this->fileSystem,
      $this->requestStack,
      $this->logger,
    );
  }

  protected function mockJsonResponse(array $data): ResponseInterface {
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('__toString')->willReturn(json_encode($data));

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')->willReturn($stream);

    return $response;
  }

  public function test_get_assets_returns_array(): void {
    $expected = [
      'data' => [
        ['uuid' => 'abc-123', 'name' => 'photo.jpg'],
        ['uuid' => 'def-456', 'name' => 'logo.png'],
      ],
    ];

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'https://dam.example.com/api/v1/assets', $this->callback(function ($options) {
        return $options['headers']['Authorization'] === 'Bearer test-token-123'
          && $options['query'] === ['search' => 'photo'];
      }))
      ->willReturn($this->mockJsonResponse($expected));

    $result = $this->client->getAssets(['search' => 'photo']);
    $this->assertSame($expected, $result);
  }

  public function test_get_asset_by_uuid(): void {
    $expected = ['uuid' => 'abc-123', 'name' => 'photo.jpg', 'mime_type' => 'image/jpeg'];

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'https://dam.example.com/api/v1/assets/abc-123', $this->anything())
      ->willReturn($this->mockJsonResponse($expected));

    $result = $this->client->getAsset('abc-123');
    $this->assertSame($expected, $result);
  }

  public function test_register_usage(): void {
    $response = $this->mockJsonResponse(['status' => 'ok']);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://dam.example.com/api/v1/assets/abc-123/usage',
        $this->callback(function ($options) {
          return $options['json']['source_entity_type'] === 'media'
            && $options['json']['source_entity_id'] === '42';
        }),
      )
      ->willReturn($response);

    // reportUsage calls \Drupal::request() for the host — we need to bootstrap that.
    $request = new Request();
    $request->headers->set('HOST', 'drupal.example.com');
    $container = new ContainerBuilder();
    $container->set('request_stack', new RequestStack());
    $container->get('request_stack')->push($request);
    \Drupal::setContainer($container);

    $this->client->reportUsage('abc-123', 'media', '42', 'https://drupal.example.com/node/1');
  }

  public function test_remove_usage(): void {
    $response = $this->mockJsonResponse(['status' => 'ok']);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'DELETE',
        'https://dam.example.com/api/v1/assets/abc-123/usage',
        $this->callback(function ($options) {
          return $options['json']['source_entity_type'] === 'media'
            && $options['json']['source_entity_id'] === '42';
        }),
      )
      ->willReturn($response);

    $request = new Request();
    $request->headers->set('HOST', 'drupal.example.com');
    $container = new ContainerBuilder();
    $container->set('request_stack', new RequestStack());
    $container->get('request_stack')->push($request);
    \Drupal::setContainer($container);

    $this->client->removeUsage('abc-123', 'media', '42');
  }

  public function test_handles_api_error(): void {
    $request = $this->createMock(RequestInterface::class);
    $exception = new RequestException(
      'Server Error',
      $request,
      $this->createMock(ResponseInterface::class),
    );

    $this->httpClient->method('request')->willThrowException($exception);

    $this->logger->expects($this->once())
      ->method('error')
      ->with($this->stringContains('Failed to fetch assets'), $this->anything());

    $result = $this->client->getAssets();
    $this->assertSame([], $result);
  }

  public function test_get_variant_url_finds_thumb(): void {
    $asset = [
      'uuid' => 'abc-123',
      'variants' => [
        ['variant_name' => 'large', 'url' => 'https://dam.example.com/large.jpg'],
        ['variant_name' => 'thumb', 'url' => 'https://dam.example.com/thumb.jpg'],
      ],
    ];

    $this->assertSame('https://dam.example.com/thumb.jpg', $this->client->getVariantUrl($asset));
    $this->assertSame('https://dam.example.com/large.jpg', $this->client->getVariantUrl($asset, 'large'));
    $this->assertNull($this->client->getVariantUrl($asset, 'nonexistent'));
  }

  public function test_get_variant_url_returns_null_for_empty_variants(): void {
    $this->assertNull($this->client->getVariantUrl([]));
    $this->assertNull($this->client->getVariantUrl(['variants' => []]));
  }

  public function test_download_asset_returns_response(): void {
    $response = $this->createMock(ResponseInterface::class);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('POST', 'https://dam.example.com/api/v1/assets/abc-123/download', $this->callback(function ($options) {
        return $options['stream'] === TRUE;
      }))
      ->willReturn($response);

    $this->assertSame($response, $this->client->downloadAsset('abc-123'));
  }

  public function test_download_asset_returns_null_on_error(): void {
    $request = $this->createMock(RequestInterface::class);
    $this->httpClient->method('request')
      ->willThrowException(new RequestException('Not found', $request));

    $this->assertNull($this->client->downloadAsset('abc-123'));
  }

  public function test_to_picker_asset_prefers_thumb_variant(): void {
    $result = $this->client->toPickerAsset([
      'uuid' => 'abc-123',
      'original_name' => 'photo.jpg',
      'mime_type' => 'image/jpeg',
      'url' => 'https://dam.example.com/original.jpg',
      'variants' => [
        ['variant_name' => 'thumb', 'url' => 'https://dam.example.com/thumb.jpg'],
      ],
    ]);

    $this->assertSame('abc-123', $result['uuid']);
    $this->assertSame('photo.jpg', $result['name']);
    $this->assertSame('image/jpeg', $result['mime']);
    $this->assertSame('https://dam.example.com/thumb.jpg', $result['thumbUrl']);
    $this->assertSame('https://dam.example.com/original.jpg', $result['url']);
  }

  public function test_to_picker_asset_falls_back_to_original_for_images_without_derivatives(): void {
    // No 'thumb'/'preview' variant (e.g. derivatives not generated yet) — an
    // image must still get a usable thumbUrl from its original file.
    $result = $this->client->toPickerAsset([
      'uuid' => 'abc-123',
      'mime_type' => 'image/png',
      'url' => 'https://dam.example.com/banner.png',
      'variants' => [],
    ]);

    $this->assertSame('https://dam.example.com/banner.png', $result['thumbUrl']);
  }

  public function test_to_picker_asset_does_not_fall_back_for_non_images(): void {
    // The raw file of a PDF/video/audio asset is not a valid <img> source, so
    // thumbUrl must stay null when there's no thumb/preview variant.
    $result = $this->client->toPickerAsset([
      'uuid' => 'abc-123',
      'mime_type' => 'application/pdf',
      'url' => 'https://dam.example.com/manual.pdf',
      'variants' => [],
    ]);

    $this->assertNull($result['thumbUrl']);
  }

  public function test_to_picker_asset_uses_preview_variant_for_non_images(): void {
    // A generated 'preview' (PDF page render, video frame) is a valid thumb.
    $result = $this->client->toPickerAsset([
      'uuid' => 'abc-123',
      'mime_type' => 'application/pdf',
      'url' => 'https://dam.example.com/manual.pdf',
      'variants' => [
        ['variant_name' => 'preview', 'url' => 'https://dam.example.com/manual-preview.jpg'],
      ],
    ]);

    $this->assertSame('https://dam.example.com/manual-preview.jpg', $result['thumbUrl']);
  }

}
