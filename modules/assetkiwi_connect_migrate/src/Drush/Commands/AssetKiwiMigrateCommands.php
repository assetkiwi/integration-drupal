<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect_migrate\Drush\Commands;

use Drupal\assetkiwi_connect_migrate\MigrationManager;
use Drupal\assetkiwi_connect_migrate\MigrationStatusStorage;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AssetKiwiMigrateCommands extends DrushCommands {

  public function __construct(
    protected MigrationManager $manager,
    protected MigrationStatusStorage $statusStorage,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('assetkiwi_connect_migrate.manager'),
      $container->get('assetkiwi_connect_migrate.status_storage'),
    );
  }

  /**
   * Lists local media eligible for migration to asset.kiwi.
   *
   * Read-only — never uploads or modifies anything.
   */
  #[CLI\Command(name: 'assetkiwi-migrate:scan')]
  #[CLI\Option(name: 'bundle', description: 'Comma-separated media type machine names to restrict to (default: all eligible local types)')]
  #[CLI\Option(name: 'limit', description: 'Maximum items to scan (0 = unlimited)')]
  #[CLI\Usage(name: 'drush assetkiwi-migrate:scan', description: 'List all local media eligible for migration')]
  #[CLI\Usage(name: 'drush assetkiwi-migrate:scan --bundle=image,document', description: 'Restrict the scan to the "image" and "document" media types')]
  public function scan(array $options = ['bundle' => '', 'limit' => 0]): void {
    $bundles = $options['bundle'] ? array_filter(array_map('trim', explode(',', $options['bundle']))) : [];
    $candidates = $this->manager->scanCandidates($bundles, (int) $options['limit']);

    if (empty($candidates)) {
      $this->logger()->success(dt('No eligible local media found.'));
      return;
    }

    $rows = [];
    $totalSize = 0;
    $eligibleCount = 0;
    foreach ($candidates as $candidate) {
      $rows[] = [
        $candidate['media_id'],
        $candidate['bundle'],
        $candidate['label'],
        $candidate['status'],
        $candidate['status'] === 'eligible' ? $this->formatBytes($candidate['size']) : '',
      ];
      if ($candidate['status'] === 'eligible') {
        $totalSize += $candidate['size'];
        $eligibleCount++;
      }
    }

    $this->io()->table(['Media ID', 'Bundle', 'Label', 'Status', 'Size'], $rows);
    $this->io()->note(sprintf('%d eligible item(s), %s total.', $eligibleCount, $this->formatBytes($totalSize)));
  }

  /**
   * Migrates local media to asset.kiwi: upload, verify, offload.
   *
   * Never deletes local files — see assetkiwi-migrate:purge for that,
   * separate, explicitly-confirmed step.
   */
  #[CLI\Command(name: 'assetkiwi-migrate:run')]
  #[CLI\Option(name: 'dry-run', description: 'Preview only — never uploads anything')]
  #[CLI\Option(name: 'bundle', description: 'Comma-separated media type machine names to restrict to')]
  #[CLI\Option(name: 'limit', description: 'Maximum items to process (0 = unlimited)')]
  #[CLI\Usage(name: 'drush assetkiwi-migrate:run --dry-run', description: 'Preview what a real run would do, without uploading anything')]
  #[CLI\Usage(name: 'drush assetkiwi-migrate:run --bundle=image --limit=50', description: 'Migrate up to 50 "image" media items')]
  public function run(array $options = ['dry-run' => FALSE, 'bundle' => '', 'limit' => 0]): void {
    $bundles = $options['bundle'] ? array_filter(array_map('trim', explode(',', $options['bundle']))) : [];
    $dryRun = (bool) $options['dry-run'];

    if ($dryRun) {
      $this->io()->note('DRY RUN — nothing will be uploaded. Note: asset.kiwi has no hash-only lookup endpoint, so this can only report "would upload"; it cannot predict whether a real run would instead dedup against an existing remote asset (that only happens via the actual upload call).');
    }

    $candidates = $this->manager->scanCandidates($bundles, (int) $options['limit']);
    $eligible = array_values(array_filter($candidates, static fn(array $c): bool => $c['status'] === 'eligible'));

    if (empty($eligible)) {
      $this->logger()->success(dt('Nothing to migrate.'));
      return;
    }

    if (!$dryRun && !$this->io()->confirm(sprintf('Migrate %d item(s) now? Local files are never deleted by this step.', count($eligible)))) {
      $this->logger()->warning(dt('Aborted.'));
      return;
    }

    $rows = [];
    $progress = $this->io()->createProgressBar(count($eligible));
    $progress->start();
    $failedCount = 0;
    foreach ($eligible as $candidate) {
      $result = $this->manager->migrateItem($candidate['media_id'], $dryRun);
      $notes = $result['error'] ?? (($result['reused'] ?? FALSE) ? 'reused existing asset' : '');
      $rows[] = [$candidate['media_id'], $candidate['bundle'], $candidate['label'], $result['status'], $notes];
      if ($result['status'] === MigrationStatusStorage::STATUS_FAILED) {
        $failedCount++;
      }
      $progress->advance();
    }
    $progress->finish();
    $this->io()->newLine(2);

    $this->io()->table(['Media ID', 'Bundle', 'Label', 'Result', 'Notes'], $rows);

    if ($failedCount > 0) {
      $this->logger()->warning(sprintf('%d item(s) failed — see the Notes column. Local files for failed items were left untouched; re-running will retry them.', $failedCount));
    }
  }

  /**
   * Shows migration status counts.
   */
  #[CLI\Command(name: 'assetkiwi-migrate:status')]
  public function status(): void {
    $counts = $this->manager->getStatusCounts();
    if (empty($counts)) {
      $this->logger()->success(dt('No migration activity recorded yet.'));
      return;
    }
    $rows = [];
    foreach ($counts as $status => $count) {
      $rows[] = [$status, $count];
    }
    $this->io()->table(['Status', 'Count'], $rows);
  }

  /**
   * Deletes local files for offloaded media, freeing disk space.
   *
   * Re-verifies each remote asset immediately before deleting its local
   * file. Never deletes the media entity itself.
   */
  #[CLI\Command(name: 'assetkiwi-migrate:purge')]
  #[CLI\Option(name: 'limit', description: 'Maximum items to purge in this run (0 = unlimited)')]
  #[CLI\Usage(name: 'drush assetkiwi-migrate:purge', description: 'Delete local files for offloaded items, after re-verifying each still exists on asset.kiwi')]
  public function purge(array $options = ['limit' => 0]): void {
    $this->io()->warning('This permanently deletes local files for media already offloaded to asset.kiwi. Each item is re-verified against asset.kiwi immediately before deletion; anything that fails verification is left untouched. The media entity itself is NEVER deleted.');
    $this->io()->warning('Rendering for offloaded media switches to asset.kiwi automatically (the view-layer render swap). Spot-check a few migrated items on your site to confirm they display correctly before purging broadly.');

    $ids = $this->statusStorage->listEntityIdsByStatus('media', MigrationStatusStorage::STATUS_OFFLOADED, (int) $options['limit']);
    if (empty($ids)) {
      $this->logger()->success(dt('Nothing to purge.'));
      return;
    }

    if (!$this->io()->confirm(sprintf('Permanently delete local files for %d offloaded item(s)?', count($ids)), FALSE)) {
      $this->logger()->warning(dt('Aborted.'));
      return;
    }

    $rows = [];
    foreach ($ids as $mediaId) {
      $result = $this->manager->purgeItem($mediaId);
      $rows[] = [$mediaId, $result['status'], $result['error'] ?? ''];
    }
    $this->io()->table(['Media ID', 'Result', 'Notes'], $rows);
  }

  private function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = $bytes;
    while ($n >= 1024 && $i < count($units) - 1) {
      $n /= 1024;
      $i++;
    }
    return ($i > 0 ? number_format($n, 1) : (string) $n) . ' ' . $units[$i];
  }

}
