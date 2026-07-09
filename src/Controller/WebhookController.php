<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Controller;

use Drupal\Core\Controller\ControllerBase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles incoming webhooks from asset.kiwi.
 */
class WebhookController extends ControllerBase {

  /**
   * How far out of date a delivery may be before it is refused, in seconds.
   */
  protected const MAX_AGE_SECONDS = 300;

  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->configFactory = $container->get('config.factory');
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
  }

  public function receive(Request $request): JsonResponse {
    $config = $this->configFactory->get('assetkiwi_connect.settings');
    $secret = $config->get('webhook_secret') ?? '';

    if (empty($secret)) {
      $this->loggerFactory->get('assetkiwi_connect')->warning('Webhook secret not configured.');
      return new JsonResponse(['error' => 'Webhook secret not configured.'], 403);
    }

    // The sender (Modules\Webhooks\Services\WebhookDispatcher::send()) signs
    // the TIMESTAMP AND BODY together and ships the timestamp alongside:
    //
    //   X-Webhook-Timestamp: <unix>
    //   X-Webhook-Signature: hash_hmac('sha256', "{$ts}.{$body}", $secret)
    //
    // Binding the timestamp into the signed message is what makes replay
    // detectable — a bare-body signature stays valid forever, so a captured
    // delivery could be resent indefinitely. Deliveries outside the window
    // are refused even when the HMAC itself is intact.
    $timestamp = (string) $request->headers->get('X-Webhook-Timestamp', '');
    if ($timestamp === '' || !ctype_digit($timestamp)) {
      $this->loggerFactory->get('assetkiwi_connect')->warning('Webhook rejected: missing or malformed timestamp.');
      return new JsonResponse(['error' => 'Missing or malformed timestamp'], 403);
    }

    if (abs(time() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
      $this->loggerFactory->get('assetkiwi_connect')->warning('Webhook rejected: timestamp outside the accepted window.');
      return new JsonResponse(['error' => 'Timestamp outside the accepted window'], 403);
    }

    $signature = $request->headers->get('X-Webhook-Signature', '');
    $body = $request->getContent();
    $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    if (!hash_equals($expected, $signature)) {
      $this->loggerFactory->get('assetkiwi_connect')->warning('Webhook signature verification failed.');
      return new JsonResponse(['error' => 'Invalid signature'], 403);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (empty($payload) || empty($payload['event'])) {
      return new JsonResponse(['error' => 'Invalid payload'], 400);
    }

    $event = $payload['event'];
    $data = $payload['data'] ?? [];

    $this->loggerFactory->get('assetkiwi_connect')->info('Received webhook: @event', ['@event' => $event]);

    match ($event) {
      'asset.updated' => $this->handleAssetUpdated($data),
      'asset.deleted' => $this->handleAssetDeleted($data),
      'webhook.test' => NULL,
      default => $this->loggerFactory->get('assetkiwi_connect')->notice('Unhandled webhook event: @event', ['@event' => $event]),
    };

    return new JsonResponse(['status' => 'ok']);
  }

  protected function handleAssetUpdated(array $data): void {
    $uuid = $data['uuid'] ?? $data['id'] ?? NULL;
    if (!$uuid) {
      return;
    }

    try {
      $mediaStorage = $this->entityTypeManager()->getStorage('media');
      $media_ids = $mediaStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_assetkiwi_uuid', $uuid)
        ->execute();

      if (empty($media_ids)) {
        return;
      }

      $entities = $mediaStorage->loadMultiple($media_ids);
      foreach ($entities as $media) {
        if ($media->hasField('field_assetkiwi_alt') && isset($data['alt_text'])) {
          $media->set('field_assetkiwi_alt', $data['alt_text']);
        }
        if ($media->hasField('field_assetkiwi_description') && isset($data['description'])) {
          $media->set('field_assetkiwi_description', $data['description']);
        }
        if ($media->hasField('name') && isset($data['original_name'])) {
          $media->setName($data['original_name']);
        }
        $media->save();
      }

      $this->loggerFactory->get('assetkiwi_connect')->info('Synced @count media entities for asset @uuid', [
        '@count' => count($entities),
        '@uuid' => $uuid,
      ]);
    }
    catch (Exception $e) {
      $this->loggerFactory->get('assetkiwi_connect')->error('Failed to sync asset @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  protected function handleAssetDeleted(array $data): void {
    $uuid = $data['uuid'] ?? $data['id'] ?? NULL;
    if (!$uuid) return;

    try {
      $mediaStorage = $this->entityTypeManager()->getStorage('media');
      $media_ids = $mediaStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_assetkiwi_uuid', $uuid)
        ->execute();

      if (empty($media_ids)) return;

      $entities = $mediaStorage->loadMultiple($media_ids);
      foreach ($entities as $media) {
        // Unpublish rather than delete to preserve references.
        $media->setUnpublished();
        $media->save();
      }

      $this->loggerFactory->get('assetkiwi_connect')->info('Unpublished @count media entities for deleted asset @uuid', [
        '@count' => count($entities),
        '@uuid' => $uuid,
      ]);
    }
    catch (Exception $e) {
      $this->loggerFactory->get('assetkiwi_connect')->error('Failed to handle deleted asset @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
