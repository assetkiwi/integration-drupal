<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\Kernel;

use Drupal\assetkiwi_connect\Form\AssetKiwiAddForm;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the server-side guards of the "Upload to asset.kiwi" element.
 *
 * @group assetkiwi_connect
 */
#[RunTestsInSeparateProcesses]
class AssetKiwiAddFormUploadTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'filter',
    'image',
    'media',
    'views',
    'media_library',
    'assetkiwi_connect',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['assetkiwi_connect']);
  }

  /**
   * Calls a protected method on a fresh AssetKiwiAddForm.
   */
  protected function callFormMethod(string $method, array $args = []): mixed {
    $form = AssetKiwiAddForm::create($this->container);
    return (new \ReflectionMethod($form, $method))->invokeArgs($form, $args);
  }

  /**
   * Creates and saves a file entity.
   */
  protected function createFile(int $owner, bool $temporary): FileInterface {
    $file = File::create([
      'uri' => 'temporary://' . $this->randomMachineName() . '.png',
      'uid' => $owner,
    ]);
    $temporary ? $file->setTemporary() : $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Only the current user's own, unused temporary files may be processed.
   */
  public function testOnlyOwnUnusedTemporaryFilesAreProcessed(): void {
    // Take uid 1 out of the way so the acting user isn't the super user.
    $this->createUser();
    $other = $this->createUser();
    $me = $this->setUpCurrentUser([], ['upload assetkiwi assets']);

    $own_temporary = $this->createFile((int) $me->id(), TRUE);
    $own_permanent = $this->createFile((int) $me->id(), FALSE);
    $foreign_temporary = $this->createFile((int) $other->id(), TRUE);
    $own_in_use = $this->createFile((int) $me->id(), TRUE);
    $this->container->get('file.usage')->add($own_in_use, 'system', 'user', $me->id());

    $files = [
      $own_temporary->id() => $own_temporary,
      $own_permanent->id() => $own_permanent,
      $foreign_temporary->id() => $foreign_temporary,
      $own_in_use->id() => $own_in_use,
    ];

    $kept = $this->callFormMethod('filterOwnTemporaryUploads', [$files]);
    $this->assertSame([(int) $own_temporary->id()], array_map('intval', array_keys($kept)));
  }

  /**
   * Upload is gated by both the setting and the permission.
   */
  public function testUploadAllowedRequiresSettingAndPermission(): void {
    $this->createUser();

    $this->setUpCurrentUser([], ['upload assetkiwi assets']);
    $this->assertTrue($this->callFormMethod('isUploadAllowed'));

    $this->config('assetkiwi_connect.settings')->set('allow_upload', FALSE)->save();
    $this->assertFalse($this->callFormMethod('isUploadAllowed'));

    $this->config('assetkiwi_connect.settings')->set('allow_upload', TRUE)->save();
    $this->setUpCurrentUser([], ['browse assetkiwi assets']);
    $this->assertFalse($this->callFormMethod('isUploadAllowed'));
  }

}
