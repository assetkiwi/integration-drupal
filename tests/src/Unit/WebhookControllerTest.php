<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\assetkiwi_connect\Controller\WebhookController;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

class WebhookControllerTest extends UnitTestCase {

  protected const WEBHOOK_SECRET = 'test-secret-key';

  protected WebhookController $controller;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected LoggerChannelInterface $logger;
  protected EntityTypeManagerInterface $entityTypeManager;

  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['webhook_secret', self::WEBHOOK_SECRET],
    ]);

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('assetkiwi_connect.settings')
      ->willReturn($config);

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->method('get')->willReturn($this->logger);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    // Build the controller via its DI pattern — we use a partial mock to
    // inject dependencies that normally come from the container.
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('logger.factory', $this->loggerFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('current_user', $this->createMock(AccountProxyInterface::class));
    \Drupal::setContainer($container);

    $this->controller = WebhookController::create($container);
  }

  protected function buildSignedRequest(array $payload, ?int $timestamp = NULL): Request {
    $body = json_encode($payload);
    $timestamp ??= time();
    // The timestamp is part of the signed message — see WebhookController and
    // Modules\Webhooks\Services\WebhookDispatcher::send().
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, self::WEBHOOK_SECRET);

    return new Request([], [], [], [], [], [
      'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
      'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
      'CONTENT_TYPE' => 'application/json',
    ], $body);
  }

  public function test_missing_timestamp_rejected(): void {
    $payload = ['event' => 'webhook.test', 'data' => []];
    $body = json_encode($payload);

    // A signature valid under the old bare-body scheme must no longer pass.
    $request = new Request([], [], [], [], [], [
      'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
      'CONTENT_TYPE' => 'application/json',
    ], $body);

    $this->assertSame(403, $this->controller->receive($request)->getStatusCode());
  }

  public function test_stale_timestamp_rejected(): void {
    // Correctly signed, but replayed outside the freshness window.
    $request = $this->buildSignedRequest(['event' => 'webhook.test', 'data' => []], time() - 3600);

    $this->assertSame(403, $this->controller->receive($request)->getStatusCode());
  }

  public function test_valid_hmac_accepted(): void {
    $payload = ['event' => 'webhook.test', 'data' => []];
    $request = $this->buildSignedRequest($payload);

    $response = $this->controller->receive($request);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('{"status":"ok"}', $response->getContent());
  }

  public function test_invalid_hmac_rejected(): void {
    $body = json_encode(['event' => 'webhook.test', 'data' => []]);

    // A fresh, well-formed timestamp, so this exercises the signature
    // comparison rather than the timestamp precondition — the missing and
    // stale timestamp cases are asserted separately below.
    $request = new Request([], [], [], [], [], [
      'HTTP_X_WEBHOOK_TIMESTAMP' => (string) time(),
      'HTTP_X_WEBHOOK_SIGNATURE' => 'bad-signature',
      'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response = $this->controller->receive($request);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringContainsString('Invalid signature', $response->getContent());
  }

  public function test_missing_signature_rejected(): void {
    $body = json_encode(['event' => 'webhook.test', 'data' => []]);

    // Timestamp present so the request gets past that precondition and is
    // actually judged on the absent signature, as the test name claims.
    $request = new Request([], [], [], [], [], [
      'HTTP_X_WEBHOOK_TIMESTAMP' => (string) time(),
      'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response = $this->controller->receive($request);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringContainsString('Invalid signature', $response->getContent());
  }

  public function test_empty_secret_rejected(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['webhook_secret', ''],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('assetkiwi_connect.settings')
      ->willReturn($config);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('logger.factory', $this->loggerFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    \Drupal::setContainer($container);

    $controller = WebhookController::create($container);

    $body = json_encode(['event' => 'webhook.test', 'data' => []]);
    $request = new Request([], [], [], [], [], [
      'HTTP_X_WEBHOOK_SIGNATURE' => 'some-sig',
      'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response = $controller->receive($request);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringContainsString('Webhook secret not configured.', $response->getContent());
  }

  public function test_asset_deleted_event(): void {
    $uuid = 'abc-123-def';
    $payload = [
      'event' => 'asset.deleted',
      'data' => ['uuid' => $uuid],
    ];

    // Set up entity query chain.
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([10 => '10']);

    $media = $this->createMock(ContentEntityInterface::class);
    $media->expects($this->once())->method('setUnpublished');
    $media->expects($this->once())->method('save');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->with([10 => '10'])->willReturn([10 => $media]);

    $this->entityTypeManager->method('getStorage')
      ->with('media')
      ->willReturn($storage);

    $request = $this->buildSignedRequest($payload);
    $response = $this->controller->receive($request);

    $this->assertSame(200, $response->getStatusCode());
  }

  public function test_invalid_payload_returns_400(): void {
    $request = $this->buildSignedRequest([]);

    $response = $this->controller->receive($request);

    $this->assertSame(400, $response->getStatusCode());
  }

  public function test_asset_updated_event_syncs_fields(): void {
    $payload = [
      'event' => 'asset.updated',
      'data' => [
        'uuid' => 'abc-123',
        'alt_text' => 'New alt text',
        'description' => 'New description',
        'original_name' => 'updated-photo.jpg',
      ],
    ];

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([5 => '5']);

    $media = $this->createMock(ContentEntityInterface::class);
    $media->method('hasField')->willReturnMap([
      ['field_assetkiwi_alt', TRUE],
      ['field_assetkiwi_description', TRUE],
      ['name', TRUE],
    ]);
    $media->expects($this->exactly(2))->method('set');
    $media->expects($this->once())->method('save');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn([5 => $media]);

    $this->entityTypeManager->method('getStorage')
      ->with('media')
      ->willReturn($storage);

    $request = $this->buildSignedRequest($payload);
    $response = $this->controller->receive($request);

    $this->assertSame(200, $response->getStatusCode());
  }

}
