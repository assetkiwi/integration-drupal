<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the asset.kiwi asset browser UI.
 *
 * @group assetkiwi_connect
 */
class AssetBrowserTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['assetkiwi_connect', 'media', 'media_library'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'claro';

  /**
   * A user with permission to browse assets.
   */
  protected $browserUser;

  /**
   * A user without any assetkiwi permissions.
   */
  protected $unauthorizedUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->browserUser = $this->createUser(['browse assetkiwi assets']);
    $this->unauthorizedUser = $this->createUser(['access content']);
  }

  /**
   * Test settings page requires administer permission.
   */
  public function testSettingsPageAccess(): void {
    $this->drupalLogin($this->unauthorizedUser);
    $this->drupalGet('/admin/config/media/assetkiwi');
    $this->assertSession()->statusCodeEquals(403);

    $admin = $this->createUser(['administer assetkiwi']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/media/assetkiwi');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Test browser page requires browse permission.
   */
  public function testBrowserPageAccess(): void {
    $this->drupalLogin($this->unauthorizedUser);
    $this->drupalGet('/admin/assetkiwi/browse');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->browserUser);
    $this->drupalGet('/admin/assetkiwi/browse');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Test settings form saves configuration.
   */
  public function testSettingsFormSubmission(): void {
    $admin = $this->createUser(['administer assetkiwi']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/media/assetkiwi');
    $this->submitForm([
      'api_url' => 'https://dam.example.com',
      'api_token' => 'test-token-123',
      'cache_lifetime' => '7200',
      'webhook_secret' => 'whsec_test',
      'mapping_thumbnail' => 'small',
      'mapping_medium' => 'medium',
      'mapping_large' => 'big',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('assetkiwi_connect.settings');
    $this->assertEquals('https://dam.example.com', $config->get('api_url'));
    $this->assertEquals('test-token-123', $config->get('api_token'));
    $this->assertEquals(7200, $config->get('cache_lifetime'));
    $this->assertEquals('whsec_test', $config->get('webhook_secret'));
    $this->assertEquals('small', $config->get('image_style_mapping.thumbnail'));
  }

}
