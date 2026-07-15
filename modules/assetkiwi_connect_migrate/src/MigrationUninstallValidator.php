<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect_migrate;

use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Blocks uninstalling this module while doing so would break rendering.
 *
 * Purged items depend entirely on this module's view-layer render swap to
 * display anything at all — their local file is gone, so without the swap
 * they become broken images. Items mid-pipeline (uploading/uploaded/verified)
 * represent unfinished work whose resumability would be lost. Items that are
 * merely offloaded (local file still present) or failed (nothing happened)
 * don't block: losing this module in that case just means they render
 * locally again, which is safe.
 */
class MigrationUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  private const BLOCKING_STATUSES = [
    MigrationStatusStorage::STATUS_PURGED,
    MigrationStatusStorage::STATUS_UPLOADING,
    MigrationStatusStorage::STATUS_UPLOADED,
    MigrationStatusStorage::STATUS_VERIFIED,
  ];

  public function __construct(
    protected MigrationStatusStorage $statusStorage,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module): array {
    if ($module !== 'assetkiwi_connect_migrate') {
      return [];
    }

    $counts = $this->statusStorage->countByStatus('media');
    $blocking = array_filter(array_intersect_key($counts, array_flip(self::BLOCKING_STATUSES)));

    if (empty($blocking)) {
      return [];
    }

    $summary = [];
    foreach ($blocking as $status => $count) {
      $summary[] = "{$count} {$status}";
    }

    return [
      $this->t(
        'Migration items exist that depend on this module (@summary). Purged items have no local file left and rely entirely on this module to display; in-progress items would lose resumability. Finish or explicitly reset these first (see assetkiwi-migrate:status).',
        ['@summary' => implode(', ', $summary)],
      ),
    ];
  }

}
