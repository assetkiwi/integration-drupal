<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect_migrate;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;

/**
 * Thin wrapper around the assetkiwi_migrate_status tracking table.
 *
 * This table is the migration's audit log and the only source of truth for
 * "has this item already been dealt with" — see assetkiwi_connect_migrate.install.
 */
class MigrationStatusStorage {

  public const STATUS_PENDING = 'pending';
  public const STATUS_UPLOADING = 'uploading';
  public const STATUS_UPLOADED = 'uploaded';
  public const STATUS_VERIFIED = 'verified';
  public const STATUS_OFFLOADED = 'offloaded';
  public const STATUS_FAILED = 'failed';
  public const STATUS_PURGED = 'purged';

  public function __construct(
    protected Connection $database,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Returns the tracking row for one entity, or NULL if never tracked.
   */
  public function getStatus(string $entityType, int $entityId): ?array {
    $result = $this->database->select('assetkiwi_migrate_status', 's')
      ->fields('s')
      ->condition('entity_type', $entityType)
      ->condition('entity_id', $entityId)
      ->execute()
      ->fetchAssoc();
    return $result ?: NULL;
  }

  /**
   * Returns tracking rows for multiple entities, keyed by entity_id.
   *
   * @return array<int, array>
   */
  public function getStatuses(string $entityType, array $entityIds): array {
    if (empty($entityIds)) {
      return [];
    }
    $rows = $this->database->select('assetkiwi_migrate_status', 's')
      ->fields('s')
      ->condition('entity_type', $entityType)
      ->condition('entity_id', $entityIds, 'IN')
      ->execute()
      ->fetchAllAssoc('entity_id', \PDO::FETCH_ASSOC);
    return $rows;
  }

  /**
   * Creates or updates the tracking row for one entity.
   *
   * Invalidates the entity's cache tag so any already-rendered/cached page
   * picks up the new status (e.g. the view-layer render swap in
   * OffloadedMediaRenderer) on next render, rather than showing stale output
   * until an unrelated cache clear.
   */
  public function upsert(string $entityType, int $entityId, array $fields): void {
    $this->database->merge('assetkiwi_migrate_status')
      ->keys(['entity_type' => $entityType, 'entity_id' => $entityId])
      ->fields($fields)
      ->execute();

    $this->cacheTagsInvalidator->invalidateTags([$entityType . ':' . $entityId]);
  }

  /**
   * Returns per-status counts for an entity type.
   *
   * @return array<string, int>
   */
  public function countByStatus(string $entityType): array {
    $query = $this->database->select('assetkiwi_migrate_status', 's')
      ->condition('entity_type', $entityType);
    $query->addField('s', 'status');
    $query->addExpression('COUNT(*)', 'total');
    $query->groupBy('s.status');

    $counts = [];
    foreach ($query->execute() as $row) {
      $counts[$row->status] = (int) $row->total;
    }
    return $counts;
  }

  /**
   * Returns entity ids with the given status, optionally limited.
   *
   * @return int[]
   */
  public function listEntityIdsByStatus(string $entityType, string $status, int $limit = 0): array {
    $query = $this->database->select('assetkiwi_migrate_status', 's')
      ->fields('s', ['entity_id'])
      ->condition('entity_type', $entityType)
      ->condition('status', $status);
    if ($limit > 0) {
      $query->range(0, $limit);
    }
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Deletes the tracking row for one entity.
   *
   * Not used by the normal pipeline (a purged item keeps its row with
   * status=purged for audit) — exists for test/reset tooling only.
   */
  public function delete(string $entityType, int $entityId): void {
    $this->database->delete('assetkiwi_migrate_status')
      ->condition('entity_type', $entityType)
      ->condition('entity_id', $entityId)
      ->execute();
  }

}
