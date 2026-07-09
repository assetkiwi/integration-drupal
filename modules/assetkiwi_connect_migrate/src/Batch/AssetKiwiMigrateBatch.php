<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect_migrate\Batch;

use Drupal\assetkiwi_connect_migrate\MigrationStatusStorage;

/**
 * Batch API operations for the migrate/purge admin UI.
 *
 * Static per Drupal's Batch API contract — each operation is invoked as its
 * own request/tick, so nothing here holds state beyond $context. Both
 * operations delegate the actual work to MigrationManager (the same service
 * the Drush commands use), so the UI and CLI paths never diverge in
 * behaviour — only in how progress is reported.
 */
class AssetKiwiMigrateBatch {

  public static function processMigrateItem(int $mediaId, string $bundle, string $label, bool $dryRun, array &$context): void {
    $manager = \Drupal::service('assetkiwi_connect_migrate.manager');
    $result = $manager->migrateItem($mediaId, $dryRun);

    $context['results']['rows'][] = [$mediaId, $bundle, $label, $result['status'], $result['error'] ?? ($result['reused'] ?? FALSE ? 'reused existing asset' : '')];
    if ($result['status'] === MigrationStatusStorage::STATUS_FAILED) {
      $context['results']['failed'] = ($context['results']['failed'] ?? 0) + 1;
    }
    $context['message'] = \Drupal::translation()->translate('Processing %label (media @id)…', ['%label' => $label, '@id' => $mediaId]);
  }

  public static function migrateFinished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    $translation = \Drupal::translation();

    if (!$success) {
      $messenger->addError($translation->translate('The migration batch did not complete successfully. Items already processed keep their recorded status; re-run to pick up the rest.'));
      return;
    }

    $rows = $results['rows'] ?? [];
    $failed = $results['failed'] ?? 0;
    if (empty($rows)) {
      $messenger->addStatus($translation->translate('Nothing was migrated.'));
      return;
    }

    if ($failed > 0) {
      $messenger->addWarning($translation->translate('Migration finished: @total item(s) processed, @failed failed. See the asset.kiwi migration log or drush assetkiwi-migrate:status for details.', [
        '@total' => count($rows),
        '@failed' => $failed,
      ]));
    }
    else {
      $messenger->addStatus($translation->translate('Migration finished: @total item(s) processed successfully.', ['@total' => count($rows)]));
    }
  }

  public static function processPurgeItem(int $mediaId, array &$context): void {
    $manager = \Drupal::service('assetkiwi_connect_migrate.manager');
    $result = $manager->purgeItem($mediaId);

    $context['results']['rows'][] = [$mediaId, $result['status'], $result['error'] ?? ''];
    if (!empty($result['error'])) {
      $context['results']['failed'] = ($context['results']['failed'] ?? 0) + 1;
    }
    $context['message'] = \Drupal::translation()->translate('Purging media @id…', ['@id' => $mediaId]);
  }

  public static function purgeFinished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    $translation = \Drupal::translation();

    if (!$success) {
      $messenger->addError($translation->translate('The purge batch did not complete successfully. No further local files were deleted beyond what already succeeded.'));
      return;
    }

    $rows = $results['rows'] ?? [];
    $failed = $results['failed'] ?? 0;
    if ($failed > 0) {
      $messenger->addWarning($translation->translate('Purge finished: @total item(s) processed, @failed could not be safely purged (local file left untouched for those). See drush assetkiwi-migrate:status.', [
        '@total' => count($rows),
        '@failed' => $failed,
      ]));
    }
    else {
      $messenger->addStatus($translation->translate('Purge finished: @total local file(s) deleted after re-verification.', ['@total' => count($rows)]));
    }
  }

}
