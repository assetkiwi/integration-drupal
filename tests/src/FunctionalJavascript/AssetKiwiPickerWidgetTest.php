<?php

declare(strict_types=1);

namespace Drupal\Tests\assetkiwi_connect\FunctionalJavascript;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the asset.kiwi picker widget's client-side behavior.
 *
 * This checkout has no live (or stubbed) asset.kiwi backend reachable from
 * the browser process that runs FunctionalJavascript tests, so these tests
 * cover what is deterministically verifiable without one: the "Browse DAM"
 * button opens a Drupal.dialog, the picker widget mounts inside it, and it
 * surfaces a clear error state instead of hanging when the DAM is
 * unreachable — the exact failure mode the corrupted pre-refactor JS
 * (malformed attribute strings) would have masked.
 *
 * Full "select an asset -> media entity created" coverage is exercised at
 * the PHP layer against a mocked HTTP client in
 * \Drupal\Tests\assetkiwi_connect\Kernel\AssetBrowserControllerTest and the
 * existing AssetKiwiAddForm::createMediaFromValue() logic. A browser-driven
 * equivalent of the happy path needs either a live asset.kiwi test instance
 * or a test-only HTTP stub for this module, neither of which exists in this
 * checkout yet.
 *
 * @group assetkiwi_connect
 */
#[RunTestsInSeparateProcesses]
class AssetKiwiPickerWidgetTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['assetkiwi_connect', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'claro';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_asset_ref',
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_asset_ref',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Asset reference',
    ])->save();

    \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', 'article')
      ->setComponent('field_asset_ref', ['type' => 'assetkiwi_browser'])
      ->save();

    $user = $this->drupalCreateUser([
      'create article content',
      'browse assetkiwi assets',
    ]);
    $this->drupalLogin($user);
  }

  /**
   * Test the Browse DAM button opens the picker and surfaces API failure.
   */
  public function testBrowseButtonOpensPickerAndSurfacesApiError(): void {
    $this->drupalGet('node/add/article');

    $this->assertSession()->buttonExists('Browse DAM')->press();

    $this->assertNotEmpty(
      $this->assertSession()->waitForElement('css', '.ui-dialog .assetkiwi-picker')
    );

    // No DAM is configured/reachable in this test environment, so the widget
    // must surface a clear error rather than an endless loading state.
    $this->assertNotEmpty(
      $this->assertSession()->waitForElementVisible('css', '.assetkiwi-picker__error')
    );
    $this->assertSession()->pageTextContains('Could not load assets from asset.kiwi.');

    // Filter controls remain usable even when the DAM is down.
    $this->assertSession()->fieldExists('Search');
  }

}
