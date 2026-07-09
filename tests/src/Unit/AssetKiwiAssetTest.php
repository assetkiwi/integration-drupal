<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\Unit;

use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\Plugin\media\Source\AssetKiwiAsset;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the hardened thumbnail resolution in the asset.kiwi media source.
 */
class AssetKiwiAssetTest extends UnitTestCase {

  /**
   * Builds an AssetKiwiAsset that exposes the protected getThumbnailUri().
   *
   * The parent MediaSourceBase constructor requires the plugin manager stack,
   * which is irrelevant to thumbnail resolution, so the anonymous subclass
   * bypasses it and only wires the two collaborators the method uses.
   */
  protected function buildSource(AssetKiwiClient $client, LoggerInterface $logger): object {
    return new class($client, $logger) extends AssetKiwiAsset {

      public function __construct(AssetKiwiClient $client, LoggerInterface $logger) {
        $this->assetKiwiClient = $client;
        $this->logger = $logger;
      }

      public function callGetThumbnailUri(array $data): ?string {
        return $this->getThumbnailUri($data);
      }

    };
  }

  /**
   * Returns NULL (without calling the client) when no UUID is present.
   */
  public function testReturnsNullWhenUuidMissing(): void {
    $client = $this->createMock(AssetKiwiClient::class);
    $client->method('resolveVariantUrl')->willReturn('https://dam.example.com/thumb.jpg');
    $client->expects($this->never())->method('cacheThumbnail');

    $logger = $this->createMock(LoggerInterface::class);
    $source = $this->buildSource($client, $logger);

    $this->assertNull($source->callGetThumbnailUri(['variants' => []]));
  }

  /**
   * Returns NULL (without calling the client) when no thumbnail URL resolves.
   */
  public function testReturnsNullWhenThumbnailUrlMissing(): void {
    $client = $this->createMock(AssetKiwiClient::class);
    $client->method('resolveVariantUrl')->willReturn(NULL);
    $client->expects($this->never())->method('cacheThumbnail');

    $logger = $this->createMock(LoggerInterface::class);
    $source = $this->buildSource($client, $logger);

    $this->assertNull($source->callGetThumbnailUri(['uuid' => 'abc-123']));
  }

  /**
   * Returns the cached URI on success.
   */
  public function testReturnsCachedUri(): void {
    $client = $this->createMock(AssetKiwiClient::class);
    $client->method('resolveVariantUrl')->willReturn('https://dam.example.com/thumb.jpg');
    $client->method('cacheThumbnail')->willReturn('public://assetkiwi_thumbnails/abc-123.jpg');

    $logger = $this->createMock(LoggerInterface::class);
    $source = $this->buildSource($client, $logger);

    $this->assertSame(
      'public://assetkiwi_thumbnails/abc-123.jpg',
      $source->callGetThumbnailUri(['uuid' => 'abc-123']),
    );
  }

  /**
   * An image asset with no thumb variant falls back to the raw original URL.
   */
  public function testImageWithoutThumbFallsBackToRawUrl(): void {
    $client = $this->createMock(AssetKiwiClient::class);
    $client->method('resolveVariantUrl')->willReturn(NULL);
    $client->expects($this->once())
      ->method('cacheThumbnail')
      ->with('abc-123', 'https://dam.example.com/original.jpg')
      ->willReturn('public://assetkiwi_thumbnails/abc-123.jpg');

    $logger = $this->createMock(LoggerInterface::class);
    $source = $this->buildSource($client, $logger);

    $this->assertSame(
      'public://assetkiwi_thumbnails/abc-123.jpg',
      $source->callGetThumbnailUri([
        'uuid' => 'abc-123',
        'mime_type' => 'image/jpeg',
        'url' => 'https://dam.example.com/original.jpg',
      ]),
    );
  }

  /**
   * A PDF with a 'preview' variant uses the preview URL as its thumbnail.
   */
  public function testPdfWithPreviewUsesPreviewUrl(): void {
    $client = $this->createMock(AssetKiwiClient::class);
    $client->method('resolveVariantUrl')
      ->willReturnCallback(static fn (array $data, string $variant): ?string =>
        $variant === 'preview' ? 'https://dam.example.com/preview.png' : NULL);
    $client->expects($this->once())
      ->method('cacheThumbnail')
      ->with('pdf-1', 'https://dam.example.com/preview.png')
      ->willReturn('public://assetkiwi_thumbnails/pdf-1.png');

    $logger = $this->createMock(LoggerInterface::class);
    $source = $this->buildSource($client, $logger);

    $this->assertSame(
      'public://assetkiwi_thumbnails/pdf-1.png',
      $source->callGetThumbnailUri([
        'uuid' => 'pdf-1',
        'mime_type' => 'application/pdf',
        'url' => 'https://dam.example.com/document.pdf',
      ]),
    );
  }

  /**
   * A PDF with no preview variant returns NULL, never caching the raw file.
   */
  public function testPdfWithoutPreviewReturnsNull(): void {
    $client = $this->createMock(AssetKiwiClient::class);
    $client->method('resolveVariantUrl')->willReturn(NULL);
    $client->expects($this->never())->method('cacheThumbnail');

    $logger = $this->createMock(LoggerInterface::class);
    $source = $this->buildSource($client, $logger);

    $this->assertNull($source->callGetThumbnailUri([
      'uuid' => 'pdf-2',
      'mime_type' => 'application/pdf',
      'url' => 'https://dam.example.com/document.pdf',
    ]));
  }

  /**
   * A video with a 'preview' variant uses the preview URL as its thumbnail.
   */
  public function testVideoWithPreviewUsesPreviewUrl(): void {
    $client = $this->createMock(AssetKiwiClient::class);
    $client->method('resolveVariantUrl')
      ->willReturnCallback(static fn (array $data, string $variant): ?string =>
        $variant === 'preview' ? 'https://dam.example.com/frame.jpg' : NULL);
    $client->expects($this->once())
      ->method('cacheThumbnail')
      ->with('vid-1', 'https://dam.example.com/frame.jpg')
      ->willReturn('public://assetkiwi_thumbnails/vid-1.jpg');

    $logger = $this->createMock(LoggerInterface::class);
    $source = $this->buildSource($client, $logger);

    $this->assertSame(
      'public://assetkiwi_thumbnails/vid-1.jpg',
      $source->callGetThumbnailUri([
        'uuid' => 'vid-1',
        'mime_type' => 'video/mp4',
        'url' => 'https://dam.example.com/movie.mp4',
      ]),
    );
  }

  /**
   * Swallows a cacheThumbnail exception, logs a warning and returns NULL.
   */
  public function testSwallowsExceptionAndLogsWarning(): void {
    $client = $this->createMock(AssetKiwiClient::class);
    $client->method('resolveVariantUrl')->willReturn('https://dam.example.com/thumb.jpg');
    $client->method('cacheThumbnail')->willThrowException(new \RuntimeException('boom'));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('Failed to cache asset.kiwi thumbnail'), $this->anything());

    $source = $this->buildSource($client, $logger);

    $this->assertNull($source->callGetThumbnailUri(['uuid' => 'abc-123']));
  }

}
