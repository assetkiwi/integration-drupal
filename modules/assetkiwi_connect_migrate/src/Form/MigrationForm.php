<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect_migrate\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\assetkiwi_connect_migrate\Batch\AssetKiwiMigrateBatch;
use Drupal\assetkiwi_connect_migrate\MigrationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scan + migrate admin UI. Wraps the same MigrationManager service the
 * Drush commands (assetkiwi-migrate:scan/:run) use, so the two surfaces
 * never diverge in behaviour.
 */
class MigrationForm extends FormBase {

  public function __construct(
    protected MigrationManager $manager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('assetkiwi_connect_migrate.manager'));
  }

  public function getFormId(): string {
    return 'assetkiwi_connect_migrate_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $counts = $this->manager->getStatusCounts();

    $form['dashboard'] = [
      '#type' => 'details',
      '#title' => $this->t('Status'),
      '#open' => TRUE,
    ];
    if (empty($counts)) {
      $form['dashboard']['empty'] = ['#markup' => '<p>' . $this->t('No migration activity recorded yet.') . '</p>'];
    }
    else {
      $rows = [];
      foreach ($counts as $status => $count) {
        $rows[] = [$status, $count];
      }
      $form['dashboard']['table'] = [
        '#type' => 'table',
        '#header' => [$this->t('Status'), $this->t('Count')],
        '#rows' => $rows,
      ];
    }

    $bundleOptions = $this->manager->getEligibleBundleOptions();

    $form['scope'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('What to migrate'),
    ];

    if (empty($bundleOptions)) {
      $form['scope']['none'] = [
        '#markup' => '<p>' . $this->t('No locally-stored (file/image) media types were found — nothing on this site is eligible for migration.') . '</p>',
      ];
      return $form;
    }

    $form['scope']['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Media types'),
      '#description' => $this->t('Restrict to specific media types. Leave all unchecked to include every eligible type.'),
      '#options' => $bundleOptions,
    ];

    $form['scope']['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Limit'),
      '#description' => $this->t('Maximum items per run. Scanning/migrating is not yet chunked internally beyond this limit, so keep this modest on large libraries and run it multiple times.'),
      '#min' => 0,
      '#default_value' => 500,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['scan'] = [
      '#type' => 'submit',
      '#value' => $this->t('Scan (preview only)'),
      '#submit' => ['::scanSubmit'],
    ];
    $form['actions']['dry_run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Dry run'),
      '#submit' => ['::migrateSubmit'],
      '#dry_run' => TRUE,
    ];
    $form['actions']['migrate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start migration'),
      '#submit' => ['::migrateSubmit'],
      '#dry_run' => FALSE,
      '#attributes' => ['class' => ['button--primary']],
    ];

    $scanResults = $form_state->get('scan_results');
    if ($scanResults !== NULL) {
      $form['scan_results'] = [
        '#type' => 'details',
        '#title' => $this->t('Scan results'),
        '#open' => TRUE,
        '#weight' => -10,
      ];
      if (empty($scanResults)) {
        $form['scan_results']['empty'] = ['#markup' => '<p>' . $this->t('No matching media found.') . '</p>'];
      }
      else {
        $rows = [];
        $totalSize = 0;
        foreach ($scanResults as $candidate) {
          $rows[] = [$candidate['media_id'], $candidate['bundle'], $candidate['label'], $candidate['status'], $candidate['status'] === 'eligible' ? $this->formatBytes($candidate['size']) : ''];
          if ($candidate['status'] === 'eligible') {
            $totalSize += $candidate['size'];
          }
        }
        $form['scan_results']['table'] = [
          '#type' => 'table',
          '#header' => [$this->t('Media ID'), $this->t('Bundle'), $this->t('Label'), $this->t('Status'), $this->t('Size')],
          '#rows' => $rows,
        ];
        $form['scan_results']['total'] = [
          '#markup' => '<p>' . $this->t('Total: @size across eligible items.', ['@size' => $this->formatBytes($totalSize)]) . '</p>',
        ];
      }
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // No-op: real work happens in the specific ::scanSubmit/::migrateSubmit
    // handlers wired per-button above.
  }

  public function scanSubmit(array &$form, FormStateInterface $form_state): void {
    $bundles = array_values(array_filter((array) $form_state->getValue('bundles')));
    $limit = (int) $form_state->getValue('limit');
    $results = $this->manager->scanCandidates($bundles, $limit);
    $form_state->set('scan_results', $results);
    $form_state->setRebuild();
  }

  public function migrateSubmit(array &$form, FormStateInterface $form_state): void {
    $dryRun = (bool) ($form_state->getTriggeringElement()['#dry_run'] ?? FALSE);
    $bundles = array_values(array_filter((array) $form_state->getValue('bundles')));
    $limit = (int) $form_state->getValue('limit');

    $candidates = $this->manager->scanCandidates($bundles, $limit);
    $eligible = array_values(array_filter($candidates, static fn(array $c): bool => $c['status'] === 'eligible'));

    if (empty($eligible)) {
      $this->messenger()->addStatus($this->t('Nothing to migrate.'));
      return;
    }

    $operations = [];
    foreach ($eligible as $candidate) {
      $operations[] = [
        [AssetKiwiMigrateBatch::class, 'processMigrateItem'],
        [$candidate['media_id'], $candidate['bundle'], $candidate['label'], $dryRun],
      ];
    }

    batch_set([
      'title' => $dryRun ? $this->t('Previewing migration…') : $this->t('Migrating media to asset.kiwi…'),
      'init_message' => $this->t('Starting…'),
      'progress_message' => $this->t('Processed @current of @total.'),
      'operations' => $operations,
      'finished' => [AssetKiwiMigrateBatch::class, 'migrateFinished'],
    ]);
  }

  /**
   * format_size() was deprecated in Drupal 10.2 in favor of
   * ByteSizeMarkup::create(), then removed entirely in Drupal 11 — this
   * site is on a core version where format_size() no longer exists. Prefer
   * ByteSizeMarkup when available, fall back to format_size() on older 10.x
   * where ByteSizeMarkup doesn't exist yet, and fall back further to a
   * manual computation if somehow neither is present.
   */
  private function formatBytes(int $bytes): string {
    if (class_exists(ByteSizeMarkup::class)) {
      return (string) ByteSizeMarkup::create($bytes);
    }
    if (function_exists('format_size')) {
      return (string) format_size($bytes);
    }

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
