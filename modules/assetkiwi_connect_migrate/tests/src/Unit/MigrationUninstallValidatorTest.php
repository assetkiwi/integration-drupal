<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect_migrate\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\assetkiwi_connect_migrate\MigrationStatusStorage;
use Drupal\assetkiwi_connect_migrate\MigrationUninstallValidator;

/**
 * @group assetkiwi_connect_migrate
 */
class MigrationUninstallValidatorTest extends UnitTestCase {

  protected MigrationStatusStorage $statusStorage;
  protected MigrationUninstallValidator $validator;

  protected function setUp(): void {
    parent::setUp();
    $this->statusStorage = $this->createMock(MigrationStatusStorage::class);
    $this->validator = new MigrationUninstallValidator($this->statusStorage, $this->getStringTranslationStub());
  }

  public function testIgnoresOtherModules(): void {
    $this->statusStorage->expects($this->never())->method('countByStatus');
    $this->assertSame([], $this->validator->validate('some_other_module'));
  }

  public function testAllowsUninstallWhenNoTrackedItems(): void {
    $this->statusStorage->method('countByStatus')->willReturn([]);
    $this->assertSame([], $this->validator->validate('assetkiwi_connect_migrate'));
  }

  public function testAllowsUninstallWhenOnlyOffloadedOrFailed(): void {
    $this->statusStorage->method('countByStatus')->willReturn([
      MigrationStatusStorage::STATUS_OFFLOADED => 5,
      MigrationStatusStorage::STATUS_FAILED => 2,
    ]);
    $this->assertSame([], $this->validator->validate('assetkiwi_connect_migrate'));
  }

  public function testBlocksUninstallWhenAnyItemIsPurged(): void {
    $this->statusStorage->method('countByStatus')->willReturn([
      MigrationStatusStorage::STATUS_OFFLOADED => 5,
      MigrationStatusStorage::STATUS_PURGED => 1,
    ]);
    $reasons = $this->validator->validate('assetkiwi_connect_migrate');
    $this->assertNotEmpty($reasons);
    $this->assertStringContainsString('1 purged', (string) $reasons[0]);
  }

  /**
   * @dataProvider inFlightStatusProvider
   */
  public function testBlocksUninstallWhenAnyItemIsInFlight(string $status): void {
    $this->statusStorage->method('countByStatus')->willReturn([$status => 1]);
    $this->assertNotEmpty($this->validator->validate('assetkiwi_connect_migrate'));
  }

  public static function inFlightStatusProvider(): array {
    return [
      [MigrationStatusStorage::STATUS_UPLOADING],
      [MigrationStatusStorage::STATUS_UPLOADED],
      [MigrationStatusStorage::STATUS_VERIFIED],
    ];
  }

}
