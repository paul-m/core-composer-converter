<?php

namespace Drupal\Tests\Composer\Plugin\ComposerConverter;

use Drupal\Composer\Plugin\ComposerConverter\DrupalInspector;
use PHPUnit\Framework\TestCase;

/**
 * Tests the DrupalInspector class.
 *
 * @coversDefaultClass Drupal\Composer\Plugin\ComposerConverter\DrupalInspector
 *
 * @group ComposerConverter
 */
class DrupalInspectorTest extends TestCase {

  /**
   * @dataProvider providerGetSemanticVersion
   * @covers ::getSemanticVersion
   */
  public function testGetSemanticVersion($drupal_version, $semantic_version) {
    $converted_version = DrupalInspector::getSemanticVersion($drupal_version);
    $this->assertEquals($semantic_version, $converted_version);
  }

  /**
   * Provides values to testArrayMergeNoDuplicates().
   *
   * @return array
   *   An array of values to test.
   */
  public function providerGetSemanticVersion() {
    return [
      ['3.0', '3.0.0'],
      ['1.x-dev', '1.x-dev'],
      ['3.12', '3.12.0'],
      ['3.0-alpha1', '3.0.0-alpha1'],
      ['3.12-beta2', '3.12.0-beta2'],
      ['4.0-rc12', '4.0.0-rc12'],
      ['0.1-rc2', '0.1.0-rc2'],
    ];
  }

  /**
   * @dataProvider providerDetermineDrupalCoreVersionFromDrupalPhp
   * @covers ::determineDrupalCoreVersionFromDrupalPhp
   */
  public function testDetermineDrupalCoreVersionFromDrupalPhp($file_contents, $expected_core_version) {
    $core_version = DrupalInspector::determineDrupalCoreVersionFromDrupalPhp($file_contents);
    $this->assertEquals($expected_core_version, $core_version);
  }

  /**
   * Provides values to determineDrupalCoreVersionFromDrupalPhp().
   *
   * @return array
   *   An array of values to test.
   */
  public function providerDetermineDrupalCoreVersionFromDrupalPhp() {
    return [
      ["const VERSION = '8.0.0';", "8.0.0"],
      ["const VERSION = '8.0.0-beta1';", "8.0.0-beta1"],
      ["const VERSION = '8.0.0-rc2';", "8.0.0-rc2"],
      ["const VERSION = '8.5.11';", "8.5.11"],
      ["const VERSION = '8.5.x-dev';", "8.5.x-dev"],
      ["const VERSION = '8.6.11-dev';", "8.6.x-dev"],
    ];
  }

}
