<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect_migrate\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\assetkiwi_connect\Client\AssetKiwiClient;
use Drupal\assetkiwi_connect\Utility\MimeIconResolver;
use Drupal\assetkiwi_connect_migrate\MigrationStatusStorage;
use Drupal\assetkiwi_connect_migrate\OffloadedMediaRenderer;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests OffloadedMediaRenderer.
 *
 * Only the pure, isolatable logic is covered here: the early-return guards
 * that don't require a real Media entity, and buildRenderArray() (tested via
 * reflection since it's the part with actual branching logic). The
 * MediaInterface/bundle/source-plugin resolution glue in ::alter() needs a
 * real Media entity and media type config to exercise meaningfully — same
 * "entity glue is unverified live" limitation noted for MigrationManager.
 *
 * @group assetkiwi_connect_migrate
 */
class OffloadedMediaRendererTest extends UnitTestCase {

  protected MigrationStatusStorage $statusStorage;
  protected AssetKiwiClient $client;
  protected OffloadedMediaRenderer $renderer;

  protected function setUp(): void {
    parent::setUp();
    $this->statusStorage = $this->createMock(MigrationStatusStorage::class);
    $this->client = $this->createMock(AssetKiwiClient::class);
    $this->renderer = new OffloadedMediaRenderer(
      $this->statusStorage,
      $this->client,
      new MimeIconResolver(),
      $this->createMock(LoggerInterface::class),
    );
  }

  public function testNoOpWhenItemNeverTracked(): void {
    $this->statusStorage->method('getStatus')->willReturn(NULL);
    $this->client->expects($this->never())->method('getAsset');

    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn(1);

    $build = ['field_media_image' => ['#theme' => 'image_formatter']];
    $original = $build;
    $this->renderer->alter($build, $media);

    $this->assertSame($original, $build);
  }

  /**
   * @dataProvider nonBlockingStatusProvider
   */
  public function testNoOpWhenStatusDoesNotWarrantSwap(string $status): void {
    $this->statusStorage->method('getStatus')->willReturn(['status' => $status, 'assetkiwi_uuid' => 'uuid-1']);
    $this->client->expects($this->never())->method('getAsset');

    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn(2);

    $build = ['field_media_image' => ['#theme' => 'image_formatter']];
    $original = $build;
    $this->renderer->alter($build, $media);

    $this->assertSame($original, $build);
  }

  public static function nonBlockingStatusProvider(): array {
    return [
      [MigrationStatusStorage::STATUS_UPLOADING],
      [MigrationStatusStorage::STATUS_UPLOADED],
      [MigrationStatusStorage::STATUS_VERIFIED],
      [MigrationStatusStorage::STATUS_FAILED],
    ];
  }

  public function testBuildRenderArrayForImageUsesImageTheme(): void {
    $result = $this->invokeBuildRenderArray([
      'mime_type' => 'image/jpeg',
      'url' => 'https://cdn.example.com/foo.jpg',
      'alt_text' => 'A foo',
    ]);

    $this->assertSame('image', $result['#theme']);
    $this->assertSame('https://cdn.example.com/foo.jpg', $result['#uri']);
    $this->assertSame('A foo', $result['#alt']);
  }

  public function testBuildRenderArrayForDocumentUsesLink(): void {
    $result = $this->invokeBuildRenderArray([
      'mime_type' => 'application/pdf',
      'url' => 'https://cdn.example.com/doc.pdf',
      'original_name' => 'report.pdf',
    ]);

    $this->assertSame('container', $result['#type']);
    $this->assertSame('report.pdf', $result['link']['#title']);
  }

  public function testBuildRenderArrayReturnsEmptyWithoutUrl(): void {
    $result = $this->invokeBuildRenderArray(['mime_type' => 'image/jpeg']);
    $this->assertSame([], $result);
  }

  private function invokeBuildRenderArray(array $data): array {
    $method = new \ReflectionMethod(OffloadedMediaRenderer::class, 'buildRenderArray');
    $method->setAccessible(TRUE);
    return $method->invoke($this->renderer, $data);
  }

}
