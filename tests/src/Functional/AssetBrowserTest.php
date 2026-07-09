<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the asset.kiwi asset browser UI.
 *
 * @group assetkiwi_connect
 */
#[RunTestsInSeparateProcesses]
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
   * Test the picker widget's JSON endpoints require the browse permission.
   *
   * The default test environment has no reachable asset.kiwi backend, so an
   * authorized request may still surface a 502 (upstream API error) — the
   * invariant under test is that permission is enforced, not that the DAM
   * itself is reachable. Endpoint response shape and filter logic are
   * covered against a mocked HTTP client in
   * \Drupal\Tests\assetkiwi_connect\Kernel\AssetBrowserControllerTest.
   */
  public function testApiEndpointsRequirePermission(): void {
    foreach (['/admin/assetkiwi/api/assets', '/admin/assetkiwi/api/facets'] as $path) {
      $this->drupalLogin($this->unauthorizedUser);
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);

      $this->drupalLogin($this->browserUser);
      $this->drupalGet($path);
      $this->assertSession()->statusCodeNotEquals(403);
    }
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
