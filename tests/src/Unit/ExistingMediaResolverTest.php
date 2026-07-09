<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\assetkiwi_connect\ExistingMediaResolver;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceInterface;
use Drupal\media\MediaTypeInterface;

/**
 * Tests dedup lookup of asset.kiwi UUIDs against existing Drupal media.
 *
 * Uses mocked entities throughout — no Drupal bootstrap needed — since
 * ExistingMediaResolver has no container dependency beyond the entity type
 * manager it's injected with.
 *
 * @group assetkiwi_connect
 */
class ExistingMediaResolverTest extends UnitTestCase {

  /**
   * Test the resolver maps UUIDs of found media entities to their IDs.
   */
  public function testFindExistingReturnsUuidToMediaIdMap(): void {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getName')->willReturn('field_assetkiwi_uuid');

    $source = $this->createMock(MediaSourceInterface::class);
    $source->method('getSourceFieldDefinition')->willReturn($field_definition);

    $media_type = $this->createMock(MediaTypeInterface::class);
    $media_type->method('getSource')->willReturn($source);
    $media_type->method('id')->willReturn('assetkiwi_asset');

    $uuid_field = $this->createMock(FieldItemListInterface::class);
    $uuid_field->method('getString')->willReturn('uuid-1');

    $existing_media = $this->createMock(MediaInterface::class);
    $existing_media->method('get')->with('field_assetkiwi_uuid')->willReturn($uuid_field);
    $existing_media->method('id')->willReturn(42);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'bundle' => 'assetkiwi_asset',
        'field_assetkiwi_uuid' => ['uuid-1', 'uuid-2'],
      ])
      ->willReturn([$existing_media]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('media')->willReturn($storage);

    $resolver = new ExistingMediaResolver($entity_type_manager);
    $result = $resolver->findExisting($media_type, ['uuid-1', 'uuid-2']);

    // uuid-2 has no matching media entity, so only uuid-1 appears.
    $this->assertSame(['uuid-1' => 42], $result);
  }

  /**
   * Test an empty UUID list short-circuits without touching entity storage.
   */
  public function testFindExistingReturnsEmptyArrayForEmptyUuids(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');

    $media_type = $this->createMock(MediaTypeInterface::class);

    $resolver = new ExistingMediaResolver($entity_type_manager);
    $this->assertSame([], $resolver->findExisting($media_type, []));
  }

  /**
   * Test a media type with no resolvable source field is a graceful no-op.
   */
  public function testFindExistingReturnsEmptyArrayWhenSourceHasNoFieldDefinition(): void {
    $source = $this->createMock(MediaSourceInterface::class);
    $source->method('getSourceFieldDefinition')->willReturn(NULL);

    $media_type = $this->createMock(MediaTypeInterface::class);
    $media_type->method('getSource')->willReturn($source);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');

    $resolver = new ExistingMediaResolver($entity_type_manager);
    $this->assertSame([], $resolver->findExisting($media_type, ['uuid-1']));
  }

}
