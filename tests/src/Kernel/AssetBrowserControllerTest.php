<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\Kernel;

use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\Controller\AssetBrowserController;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the JSON endpoints backing the asset.kiwi picker widget.
 *
 * @group assetkiwi_connect
 */
#[RunTestsInSeparateProcesses]
class AssetBrowserControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['assetkiwi_connect', 'media'];

  /**
   * The outgoing HTTP request/response history from the last mocked client.
   */
  protected array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['assetkiwi_connect']);
  }

  /**
   * Builds a controller whose API client is backed by a Guzzle mock queue.
   */
  protected function buildController(array $responses): AssetBrowserController {
    $this->history = [];
    $handlerStack = HandlerStack::create(new MockHandler($responses));
    $handlerStack->push(Middleware::history($this->history));
    $httpClient = new GuzzleClient(['handler' => $handlerStack]);

    $client = new AssetKiwiClient(
      $this->container->get('config.factory'),
      $httpClient,
      $this->container->get('logger.factory'),
      $this->container->get('file_system'),
      $this->container->get('request_stack'),
      $this->container->get('logger.factory')->get('assetkiwi_connect'),
    );

    $controller = AssetBrowserController::create($this->container);
    $property = new \ReflectionProperty(AssetBrowserController::class, 'assetKiwiClient');
    $property->setAccessible(true);
    $property->setValue($controller, $client);

    return $controller;
  }

  /**
   * Test assets() normalizes each item into the picker's flat shape.
   */
  public function testAssetsEndpointReturnsNormalizedShape(): void {
    $controller = $this->buildController([
      new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
        'data' => [
          [
            'uuid' => 'abc-1',
            'original_name' => 'Photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
            'width' => 800,
            'height' => 600,
            'variants' => [
              ['variant_name' => 'thumb', 'url' => 'https://dam.example.com/thumb.jpg'],
            ],
          ],
        ],
        'meta' => ['total' => 1, 'per_page' => 24, 'last_page' => 1],
      ])),
    ]);

    $response = $controller->assets(Request::create('/admin/assetkiwi/api/assets', 'GET', ['page' => 1]));
    $this->assertEquals(200, $response->getStatusCode());

    $body = json_decode($response->getContent(), TRUE);
    $this->assertEquals([
      'uuid' => 'abc-1',
      'name' => 'Photo.jpg',
      'mime' => 'image/jpeg',
      'size' => 2048,
      'width' => 800,
      'height' => 600,
      'thumbUrl' => 'https://dam.example.com/thumb.jpg',
      'url' => NULL,
      'existingMediaId' => NULL,
    ], $body['data'][0]);
    $this->assertEquals([
      'current_page' => 1,
      'total' => 1,
      'per_page' => 24,
      'last_page' => 1,
    ], $body['meta']);
  }

  /**
   * Test a user-chosen type outside allowed_types is dropped, not passed through.
   */
  public function testAssetsEndpointDropsDisallowedUserType(): void {
    $controller = $this->buildController([
      new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode(['data' => [], 'meta' => []])),
    ]);

    $controller->assets(Request::create('/admin/assetkiwi/api/assets', 'GET', [
      'type' => 'video',
      'allowed_types' => 'image,document',
    ]));

    $this->assertCount(1, $this->history);
    $query = $this->history[0]['request']->getUri()->getQuery();
    $this->assertStringNotContainsString('type=', $query);
  }

  /**
   * Test a single allowed type is auto-applied when the user picks none.
   */
  public function testAssetsEndpointAutoAppliesSingleAllowedType(): void {
    $controller = $this->buildController([
      new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode(['data' => [], 'meta' => []])),
    ]);

    $controller->assets(Request::create('/admin/assetkiwi/api/assets', 'GET', [
      'allowed_types' => 'document',
    ]));

    $this->assertCount(1, $this->history);
    $query = $this->history[0]['request']->getUri()->getQuery();
    $this->assertStringContainsString('type=document', $query);
  }

  /**
   * Test an unknown bundle is ignored rather than causing an error.
   */
  public function testAssetsEndpointIgnoresUnknownBundle(): void {
    $controller = $this->buildController([
      new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
        'data' => [['uuid' => 'abc-1', 'name' => 'Test']],
        'meta' => ['total' => 1],
      ])),
    ]);

    $response = $controller->assets(Request::create('/admin/assetkiwi/api/assets', 'GET', ['bundle' => 'does_not_exist']));
    $this->assertEquals(200, $response->getStatusCode());

    $body = json_decode($response->getContent(), TRUE);
    $this->assertNull($body['data'][0]['existingMediaId']);
  }

  /**
   * Test an unreachable API surfaces as a 502 with an error payload.
   */
  public function testAssetsEndpointReturnsErrorStatusOnApiFailure(): void {
    $controller = $this->buildController([
      new ConnectException('Connection refused', new GuzzleRequest('GET', 'https://dam.example.com/api/v1/assets')),
    ]);

    $response = $controller->assets(Request::create('/admin/assetkiwi/api/assets', 'GET'));
    $this->assertEquals(502, $response->getStatusCode());

    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('error', $body);
  }

  /**
   * Test facets() exposes the SLUG as the option value (not the numeric id).
   *
   * The list API filters collection/tag by slug, so the dropdown value the
   * picker sends back must be the slug.
   */
  public function testFacetsEndpointUsesSlugAsOptionValue(): void {
    $controller = $this->buildController([
      new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
        'data' => [['id' => 1, 'slug' => 'marketing', 'name' => 'Marketing']],
      ])),
      new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
        'data' => [['id' => 7, 'slug' => 'hero', 'name' => 'Hero']],
      ])),
    ]);

    $response = $controller->facets();
    $this->assertEquals(200, $response->getStatusCode());

    $body = json_decode($response->getContent(), TRUE);
    $this->assertEquals([['id' => 'marketing', 'name' => 'Marketing']], $body['collections']);
    $this->assertEquals([['id' => 'hero', 'name' => 'Hero']], $body['tags']);
  }

}
