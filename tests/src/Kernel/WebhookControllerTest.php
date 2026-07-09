<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the webhook receiver.
 *
 * @group assetkiwi_connect
 */
class WebhookControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['assetkiwi_connect', 'system', 'media'];

  /**
   * The webhook controller.
   */
  protected $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['assetkiwi_connect']);
    $this->controller = \Drupal::service('controller_resolver')
      ->getControllerFromDefinition('\Drupal\assetkiwi_connect\Controller\WebhookController::receive');
  }

  /**
   * Test webhook returns 403 when secret is not configured.
   */
  public function testWebhookNoSecret(): void {
    $request = Request::create('/assetkiwi/webhook', 'POST', [], [], [], [], '{"event":"webhook.test"}');
    $request->headers->set('Content-Type', 'application/json');

    $response = $this->controller->receive($request);
    $this->assertEquals(403, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Webhook secret not configured.', $body['error']);
  }

  /**
   * Test webhook returns 403 for invalid signature.
   */
  public function testWebhookInvalidSignature(): void {
    $config = \Drupal::configFactory()->getEditable('assetkiwi_connect.settings');
    $config->set('webhook_secret', 'test-secret')->save();

    $request = Request::create('/assetkiwi/webhook', 'POST', [], [], [], [], '{"event":"webhook.test"}');
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('X-Webhook-Signature', 'invalid-signature');

    $response = $this->controller->receive($request);
    $this->assertEquals(403, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid signature', $body['error']);
  }

  /**
   * Test webhook returns 400 for invalid payload.
   */
  public function testWebhookInvalidPayload(): void {
    $config = \Drupal::configFactory()->getEditable('assetkiwi_connect.settings');
    $config->set('webhook_secret', 'test-secret')->save();

    $body = json_encode(['foo' => 'bar']);
    $signature = hash_hmac('sha256', $body, 'test-secret');

    $request = Request::create('/assetkiwi/webhook', 'POST', [], [], [], [], $body);
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('X-Webhook-Signature', $signature);

    $response = $this->controller->receive($request);
    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Test valid webhook test event returns 200.
   */
  public function testWebhookValidTestEvent(): void {
    $config = \Drupal::configFactory()->getEditable('assetkiwi_connect.settings');
    $config->set('webhook_secret', 'test-secret')->save();

    $body = json_encode(['event' => 'webhook.test', 'data' => []]);
    $signature = hash_hmac('sha256', $body, 'test-secret');

    $request = Request::create('/assetkiwi/webhook', 'POST', [], [], [], [], $body);
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('X-Webhook-Signature', $signature);

    $response = $this->controller->receive($request);
    $this->assertEquals(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertEquals('ok', $body['status']);
  }

}
