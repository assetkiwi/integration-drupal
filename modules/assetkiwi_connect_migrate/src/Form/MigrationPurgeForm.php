<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect_migrate\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\assetkiwi_connect_migrate\Batch\AssetKiwiMigrateBatch;
use Drupal\assetkiwi_connect_migrate\MigrationStatusStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Purge admin UI — separate route, own explicit confirmation, per the
 * "airtight, no data loss" mandate this feature was built under. Never
 * reachable from the main migrate form; deletion only happens from here.
 */
class MigrationPurgeForm extends FormBase {

  public function __construct(
    protected MigrationStatusStorage $statusStorage,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('assetkiwi_connect_migrate.status_storage'));
  }

  public function getFormId(): string {
    return 'assetkiwi_connect_migrate_purge_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $offloadedCount = $this->statusStorage->countByStatus('media')[MigrationStatusStorage::STATUS_OFFLOADED] ?? 0;

    if ($offloadedCount === 0) {
      $form['empty'] = ['#markup' => '<p>' . $this->t('Nothing to purge — no media is currently in the offloaded state.') . '</p>'];
      return $form;
    }

    $form['warning'] = [
      '#markup' => '<div class="messages messages--warning">' .
        '<p>' . $this->t('This permanently deletes local files for media already offloaded to asset.kiwi (@count item(s) currently eligible). Each item is re-verified against asset.kiwi immediately before its local file is deleted; anything that fails verification is left untouched. The media entity itself is never deleted.', ['@count' => $offloadedCount]) . '</p>' .
        '<p>' . $this->t('Rendering for offloaded media is switched to asset.kiwi automatically once migrated. Spot-check a few migrated items on your site to confirm they display correctly before purging broadly.') . '</p>' .
        '</div>',
    ];

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Limit'),
      '#description' => $this->t('Maximum items to purge in this run (0 = unlimited).'),
      '#min' => 0,
      '#default_value' => 0,
    ];

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand this permanently deletes local files and cannot be undone.'),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purge local files'),
      '#attributes' => ['class' => ['button--danger']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->getValue('confirm')) {
      $form_state->setErrorByName('confirm', $this->t('You must confirm you understand this permanently deletes local files.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $limit = (int) $form_state->getValue('limit');
    $ids = $this->statusStorage->listEntityIdsByStatus('media', MigrationStatusStorage::STATUS_OFFLOADED, $limit);

    if (empty($ids)) {
      $this->messenger()->addStatus($this->t('Nothing to purge.'));
      return;
    }

    $operations = [];
    foreach ($ids as $mediaId) {
      $operations[] = [[AssetKiwiMigrateBatch::class, 'processPurgeItem'], [$mediaId]];
    }

    batch_set([
      'title' => $this->t('Purging local files…'),
      'init_message' => $this->t('Starting…'),
      'progress_message' => $this->t('Processed @current of @total.'),
      'operations' => $operations,
      'finished' => [AssetKiwiMigrateBatch::class, 'purgeFinished'],
    ]);
  }

}
