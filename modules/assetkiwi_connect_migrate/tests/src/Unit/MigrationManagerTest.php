<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect_migrate\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect_migrate\MigrationManager;
use Drupal\assetkiwi_connect_migrate\MigrationStatusStorage;
use Psr\Log\LoggerInterface;

/**
 * Tests the migration pipeline's safety properties.
 *
 * The central concern (per explicit instruction: "airtight, no data loss")
 * is proving two things hold under failure: a failed verification never
 * flips an item to 'offloaded', and purge never deletes a local file unless
 * the remote asset was just re-confirmed to exist.
 *
 * @group assetkiwi_connect_migrate
 */
class MigrationManagerTest extends UnitTestCase {

  protected AssetKiwiClient $client;
  protected MigrationStatusStorage $statusStorage;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileSystemInterface $fileSystem;
  protected MigrationManager $manager;

  /**
   * Every upsert() call made during a test, in order, for assertion.
   */
  protected array $upsertCalls = [];

  protected function setUp(): void {
    parent::setUp();

    $this->client = $this->createMock(AssetKiwiClient::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->fileSystem = $this->createMock(FileSystemInterface::class);

    $this->statusStorage = $this->createMock(MigrationStatusStorage::class);
    $this->upsertCalls = [];
    $this->statusStorage->method('upsert')
      ->willReturnCallback(function (string $entityType, int $entityId, array $fields): void {
        $this->upsertCalls[] = $fields;
      });

    $this->manager = new MigrationManager(
      $this->client,
      $this->statusStorage,
      $this->entityTypeManager,
      $this->fileSystem,
      $this->createMock(LoggerInterface::class),
    );
  }

  protected function localFile(string $realpath, int $size): array {
    return [
      'realpath' => $realpath,
      'uri' => 'public://test.jpg',
      'filename' => 'test.jpg',
      'mime' => 'image/jpeg',
      'size' => $size,
    ];
  }

  public function testNewUploadIsVerifiedAndOffloaded(): void {
    $tmp = $this->createTempFile('hello world');

    $this->client->method('uploadAsset')->willReturn(['uuid' => 'uuid-1', 'reused' => FALSE]);
    $this->client->method('getAsset')->willReturn(['uuid' => 'uuid-1', 'size' => strlen('hello world')]);
    $this->client->method('normalizeAsset')->willReturnArgument(0);

    $result = $this->manager->processLocalFile('media', 1, $this->localFile($tmp, strlen('hello world')));

    $this->assertSame(MigrationStatusStorage::STATUS_OFFLOADED, $result['status']);
    $this->assertSame('uuid-1', $result['uuid']);
    $this->assertFalse($result['reused']);
    $this->assertNull($result['error']);

    $statuses = array_column($this->upsertCalls, 'status');
    $this->assertSame(
      [MigrationStatusStorage::STATUS_UPLOADING, MigrationStatusStorage::STATUS_UPLOADED, MigrationStatusStorage::STATUS_VERIFIED, MigrationStatusStorage::STATUS_OFFLOADED],
      $statuses,
    );

    unlink($tmp);
  }

  public function testReusedUploadIsStillVerifiedAndOffloaded(): void {
    $tmp = $this->createTempFile('same bytes');

    $this->client->method('uploadAsset')->willReturn(['uuid' => 'existing-uuid', 'reused' => TRUE]);
    $this->client->method('getAsset')->willReturn(['uuid' => 'existing-uuid', 'size' => strlen('same bytes')]);
    $this->client->method('normalizeAsset')->willReturnArgument(0);

    $result = $this->manager->processLocalFile('media', 2, $this->localFile($tmp, strlen('same bytes')));

    $this->assertSame(MigrationStatusStorage::STATUS_OFFLOADED, $result['status']);
    $this->assertTrue($result['reused']);

    unlink($tmp);
  }

  /**
   * Upload failure must never reach verify, and must never offload.
   */
  public function testUploadFailureFailsWithoutVerifyingOrOffloading(): void {
    $tmp = $this->createTempFile('data');

    $this->client->method('uploadAsset')->willReturn(NULL);
    $this->client->method('getLastError')->willReturn('Connection refused');
    $this->client->expects($this->never())->method('getAsset');

    $result = $this->manager->processLocalFile('media', 3, $this->localFile($tmp, strlen('data')));

    $this->assertSame(MigrationStatusStorage::STATUS_FAILED, $result['status']);
    $this->assertSame('Connection refused', $result['error']);
    $this->assertNotContains(MigrationStatusStorage::STATUS_OFFLOADED, array_column($this->upsertCalls, 'status'));

    unlink($tmp);
  }

  /**
   * The core safety property: a verification size mismatch must never
   * result in an 'offloaded' status being written.
   */
  public function testVerifySizeMismatchFailsAndNeverOffloads(): void {
    $tmp = $this->createTempFile('twelve bytes');

    $this->client->method('uploadAsset')->willReturn(['uuid' => 'uuid-mismatch', 'reused' => FALSE]);
    $this->client->method('getAsset')->willReturn(['uuid' => 'uuid-mismatch', 'size' => 999999]);
    $this->client->method('normalizeAsset')->willReturnArgument(0);

    $result = $this->manager->processLocalFile('media', 4, $this->localFile($tmp, strlen('twelve bytes')));

    $this->assertSame(MigrationStatusStorage::STATUS_FAILED, $result['status']);
    $this->assertStringContainsString('does not match local size', $result['error']);
    $this->assertNotContains(MigrationStatusStorage::STATUS_OFFLOADED, array_column($this->upsertCalls, 'status'));

    unlink($tmp);
  }

  public function testVerifyMissingAssetFails(): void {
    $tmp = $this->createTempFile('x');

    $this->client->method('uploadAsset')->willReturn(['uuid' => 'gone', 'reused' => FALSE]);
    $this->client->method('getAsset')->willReturn([]);

    $result = $this->manager->processLocalFile('media', 5, $this->localFile($tmp, 1));

    $this->assertSame(MigrationStatusStorage::STATUS_FAILED, $result['status']);
    $this->assertStringContainsString('not found after upload', $result['error']);

    unlink($tmp);
  }

  public function testDryRunNeverCallsUploadAsset(): void {
    $this->client->expects($this->never())->method('uploadAsset');
    $this->statusStorage->expects($this->never())->method('upsert');

    $result = $this->manager->processLocalFile('media', 6, $this->localFile('/nonexistent', 100), TRUE);

    $this->assertSame('would_upload', $result['status']);
  }

  /**
   * Idempotency: an already-offloaded item must be skipped entirely, without
   * even touching the entity type manager (let alone re-uploading).
   */
  public function testMigrateItemSkipsAlreadyOffloadedWithoutTouchingEntities(): void {
    $this->statusStorage->method('getStatus')->willReturn([
      'status' => MigrationStatusStorage::STATUS_OFFLOADED,
      'assetkiwi_uuid' => 'uuid-already',
    ]);
    $this->entityTypeManager->expects($this->never())->method('getStorage');
    $this->client->expects($this->never())->method('uploadAsset');

    $result = $this->manager->migrateItem(7);

    $this->assertTrue($result['skipped']);
    $this->assertSame(MigrationStatusStorage::STATUS_OFFLOADED, $result['status']);
  }

  public function testPurgeRefusesItemsNotInOffloadedState(): void {
    $this->statusStorage->method('getStatus')->willReturn(['status' => MigrationStatusStorage::STATUS_PENDING]);
    $this->client->expects($this->never())->method('getAsset');

    $result = $this->manager->purgeItem(8);

    $this->assertTrue($result['skipped']);
    $this->assertStringContainsString('Refusing to purge', $result['error']);
  }

  /**
   * The core purge safety property: if the remote asset can't be
   * re-verified, the local file must be left completely untouched — proven
   * here by asserting a real file on disk still exists afterward.
   */
  public function testPurgeAbortsAndLeavesFileUntouchedWhenRemoteVerificationFails(): void {
    $tmp = $this->createTempFile('still here');

    $this->statusStorage->method('getStatus')->willReturn([
      'status' => MigrationStatusStorage::STATUS_OFFLOADED,
      'assetkiwi_uuid' => 'uuid-gone',
      'local_uri' => $tmp,
    ]);
    $this->client->method('getAsset')->willReturn([]);
    $mediaStorage = $this->createMock(EntityStorageInterface::class);
    $mediaStorage->method('load')->willReturn(NULL);
    $this->entityTypeManager->method('getStorage')->with('media')->willReturn($mediaStorage);

    $result = $this->manager->purgeItem(9);

    $this->assertSame(MigrationStatusStorage::STATUS_FAILED, $result['status']);
    $this->assertFileExists($tmp, 'Local file must remain untouched when remote verification fails.');

    unlink($tmp);
  }

  public function testPurgeDeletesLocalFileOnlyAfterVerification(): void {
    $tmp = $this->createTempFile('to be purged');

    $this->statusStorage->method('getStatus')->willReturn([
      'status' => MigrationStatusStorage::STATUS_OFFLOADED,
      'assetkiwi_uuid' => 'uuid-confirmed',
      'local_uri' => $tmp,
    ]);
    $this->client->method('getAsset')->willReturn(['uuid' => 'uuid-confirmed']);
    $mediaStorage = $this->createMock(EntityStorageInterface::class);
    $mediaStorage->method('load')->willReturn(NULL);
    $this->entityTypeManager->method('getStorage')->with('media')->willReturn($mediaStorage);
    $this->fileSystem->method('realpath')->willReturnArgument(0);

    $result = $this->manager->purgeItem(10);

    $this->assertSame(MigrationStatusStorage::STATUS_PURGED, $result['status']);
    $this->assertFileDoesNotExist($tmp);
  }

  private function createTempFile(string $contents): string {
    $path = tempnam(sys_get_temp_dir(), 'akmigrate_');
    file_put_contents($path, $contents);
    return $path;
  }

}
