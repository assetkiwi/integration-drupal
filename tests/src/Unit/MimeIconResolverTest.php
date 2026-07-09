<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\Unit;

use Drupal\assetkiwi_connect\Utility\MimeIconResolver;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the framework-light MIME icon resolver.
 */
class MimeIconResolverTest extends UnitTestCase {

  /**
   * The resolver under test.
   */
  protected MimeIconResolver $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->resolver = new MimeIconResolver();
  }

  /**
   * @dataProvider bucketProvider
   */
  public function testGetIconBucket(string $mimeType, string $expected): void {
    $this->assertSame($expected, $this->resolver->getIconBucket($mimeType));
  }

  /**
   * Provides MIME types and their expected bucket keys.
   *
   * @return array<string, array{string, string}>
   */
  public static function bucketProvider(): array {
    return [
      'pdf' => ['application/pdf', 'pdf'],
      'word doc' => ['application/msword', 'doc'],
      'word docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc'],
      'excel sheet' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'spreadsheet'],
      'csv' => ['text/csv', 'spreadsheet'],
      'powerpoint' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'presentation'],
      'zip' => ['application/zip', 'archive'],
      'image' => ['image/png', 'image'],
      'video' => ['video/mp4', 'video'],
      'audio' => ['audio/mpeg', 'audio'],
      'plain text' => ['text/plain', 'text'],
      'unknown' => ['application/octet-stream', 'generic'],
      'uppercase and padded' => ['  APPLICATION/PDF  ', 'pdf'],
    ];
  }

  /**
   * Outside a Drupal bootstrap the core icon class falls back gracefully.
   */
  public function testGetCoreIconClassFallback(): void {
    if (class_exists('\Drupal\file\IconMimeTypes') || function_exists('file_icon_class')) {
      $this->markTestSkipped('Drupal core file icon classifier is loaded.');
    }
    $this->assertSame('application-octet-stream', $this->resolver->getCoreIconClass('application/pdf'));
  }

}
