<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaTypeInterface;

/** Finds which asset.kiwi UUIDs already have a local media entity. */
class ExistingMediaResolver {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns which of the given UUIDs already have a media entity.
   *
   * @param \Drupal\media\MediaTypeInterface $media_type
   *   The asset.kiwi-sourced media type to check against.
   * @param string[] $uuids
   *   Asset UUIDs to check.
   *
   * @return array<string, int|string>
   *   Map of asset UUID => existing media entity ID, for UUIDs that already
   *   have one. UUIDs with no existing entity are omitted.
   */
  public function findExisting(MediaTypeInterface $media_type, array $uuids): array {
    $uuids = array_values(array_filter($uuids));
    if (empty($uuids)) {
      return [];
    }

    $source_field_name = $media_type->getSource()->getSourceFieldDefinition($media_type)?->getName();
    if (!$source_field_name) {
      return [];
    }

    $media_entities = $this->entityTypeManager->getStorage('media')->loadByProperties([
      'bundle' => $media_type->id(),
      $source_field_name => $uuids,
    ]);

    $map = [];
    foreach ($media_entities as $media_entity) {
      $uuid = $media_entity->get($source_field_name)->getString();
      if ($uuid !== '') {
        $map[$uuid] = $media_entity->id();
      }
    }
    return $map;
  }

}
