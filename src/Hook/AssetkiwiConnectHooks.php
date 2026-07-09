<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Hook;

use Drupal\media\MediaInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Psr\Log\LoggerInterface;

/**
 * Hook implementations for the assetkiwi_connect module.
 */
class AssetkiwiConnectHooks {

  use StringTranslationTrait;

  public function __construct(
    protected AssetKiwiClient $client,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    switch ($route_name) {
      case 'help.page.assetkiwi_connect':
        return '<p>' . $this->t('Connects Drupal to the asset.kiwi digital asset management platform. Configure the connection at <a href=":settings">asset.kiwi Settings</a>.', [':settings' => Url::fromRoute('assetkiwi_connect.settings')->toString()]) . '</p>';
    }
    return '';
  }

  /**
   * Implements hook_ENTITY_TYPE_insert().
   */
  #[Hook('media_insert')]
  public function entityInsert(MediaInterface $entity): void {
    $this->trackUsage($entity);
  }

  /**
   * Implements hook_ENTITY_TYPE_update().
   */
  #[Hook('media_update')]
  public function entityUpdate(MediaInterface $entity): void {
    $this->trackUsage($entity);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete().
   */
  #[Hook('media_delete')]
  public function entityDelete(MediaInterface $entity): void {
    $this->removeUsage($entity);
  }

  /**
   * Reports asset usage to asset.kiwi for media entities.
   */
  protected function trackUsage(MediaInterface $entity): void {
    if (!$entity->hasField('field_assetkiwi_uuid')) {
      return;
    }

    $uuid = $entity->get('field_assetkiwi_uuid')->getString();
    if (empty($uuid)) {
      return;
    }

    try {
      $this->client->reportUsage(
        $uuid,
        $entity->getEntityTypeId(),
        (string) $entity->id(),
        $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
      );
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to track usage for @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Removes asset usage from asset.kiwi for media entities.
   */
  protected function removeUsage(MediaInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'media') {
      return;
    }

    if (!$entity->hasField('field_assetkiwi_uuid')) {
      return;
    }

    $uuid = $entity->get('field_assetkiwi_uuid')->getString();
    if (empty($uuid)) {
      return;
    }

    try {
      $this->client->removeUsage(
        $uuid,
        $entity->getEntityTypeId(),
        (string) $entity->id(),
      );
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to remove usage for @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
