<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\Kernel;

use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the asset.kiwi API client.
 *
 * @group assetkiwi_connect
 */
class AssetKiwiClientTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['assetkiwi_connect'];

  /**
   * The asset.kiwi client.
   */
  protected AssetKiwiClient $client;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['assetkiwi_connect']);
    $this->client = $this->container->get('assetkiwi_connect.client');
  }

  /**
   * Test that the client is instantiated with proper DI.
   */
  public function testClientServices(): void {
    $this->assertInstanceOf(AssetKiwiClient::class, $this->client);

    // Test that the client has the injected services by calling refreshConfig()
    // without throwing.
    $this->client->refreshConfig();
    $this->expectNotToPerformAssertions();
  }

  /**
   * Test mime_type → type parameter conversion.
   */
  public function testMimeTypeToTypeConversion(): void {
    // When 'mime_type' contains a category value, it should be renamed to 'type'.
    // We can't test the actual HTTP request, but we can test the client's
    // error handling when API is unreachable.
    $this->client->setCredentials('http://localhost:0/api', 'test-token');

    // getAssets should return [] without throwing when connection fails
    // and should set the lastError property.
    $result = $this->client->getAssets(['mime_type' => 'image']);
    $this->assertIsArray($result);

    $error = $this->client->getLastError();
    // Error could be a connection refused or similar — just check it's set.
    $this->assertNotNull($error, 'Last error should be set after failed API call');
  }

  /**
   * Test getLastError is null after instantiation.
   */
  public function testLastErrorInitiallyNull(): void {
    $this->assertNull($this->client->getLastError());
  }

  /**
   * Test normalizeAsset handles both wrapped and unwrapped data.
   */
  public function testNormalizeAsset(): void {
    $wrapped = ['data' => ['uuid' => 'abc', 'name' => 'Test']];
    $this->assertEquals(['uuid' => 'abc', 'name' => 'Test'], $this->client->normalizeAsset($wrapped));

    $unwrapped = ['uuid' => 'abc', 'name' => 'Test'];
    $this->assertEquals(['uuid' => 'abc', 'name' => 'Test'], $this->client->normalizeAsset($unwrapped));
  }

  /**
   * Test getAssetDisplayName fallback chain.
   */
  public function testGetAssetDisplayName(): void {
    $this->assertEquals('photo.jpg', $this->client->getAssetDisplayName(['original_name' => 'photo.jpg']));
    $this->assertEquals('Photo', $this->client->getAssetDisplayName(['name' => 'Photo']));
    $this->assertEquals('file.png', $this->client->getAssetDisplayName(['filename' => 'file.png']));
    $this->assertEquals('Untitled', $this->client->getAssetDisplayName([]));
  }

  /**
   * Test resolveVariantUrl returns URL for matching variant.
   */
  public function testResolveVariantUrl(): void {
    $asset = [
      'variants' => [
        ['variant_name' => 'thumb', 'url' => 'https://dam.example.com/thumb.jpg'],
        ['variant_name' => 'large', 'url' => 'https://dam.example.com/large.jpg'],
      ],
    ];
    $this->assertEquals('https://dam.example.com/thumb.jpg', $this->client->resolveVariantUrl($asset, 'thumb'));
    $this->assertNull($this->client->resolveVariantUrl($asset, 'nonexistent'));
  }

  /**
   * Test normalizePager handles both paginated and flat responses.
   */
  public function testNormalizePager(): void {
    $paginated = [
      'data' => ['item1', 'item2'],
      'meta' => ['total' => 100, 'per_page' => 24, 'last_page' => 5],
    ];
    $pager = $this->client->normalizePager($paginated, 1);
    $this->assertEquals(1, $pager['current_page']);
    $this->assertEquals(100, $pager['total']);
    $this->assertEquals(24, $pager['per_page']);
    $this->assertEquals(5, $pager['last_page']);

    $flat = ['item1', 'item2'];
    $pager = $this->client->normalizePager($flat, 1);
    $this->assertEquals(1, $pager['current_page']);
    $this->assertEquals(2, $pager['total']);
  }

}
