<?php

namespace Drupal\Tests\Composer\Plugin\ComposerConverter;

use Drupal\Composer\Plugin\ComposerConverter\Extension\Extension;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;

/**
 * Tests the Extension class.
 *
 * @coversDefaultClass Drupal\Composer\Plugin\ComposerConverter\Extension\Extension
 * @group ComposerConverter
 */
class ExtensionTest extends TestCase {

  public function providerInfoFiles() {
    return [
      [
        'D7-style info file',
        NULL,
        'module.info',
        'name = D7-style info file',
      ],
      [
        'D7-style info file with project',
        'contrib_project',
        'module.info',
        "name = D7-style info file with project\nproject = contrib_project",
      ],
    ];
  }

  /**
   * @covers ::getParsedInfo
   * @dataProvider providerInfoFiles
   */
  public function testGetParsedInfo($expected_name, $expected_project, $info_file_name, $info_file_contents) {
    vfsStream::setup('info_root', NULL, [
      $info_file_name => $info_file_contents,
    ]);

    $extension = new Extension(
      new \SplFileInfo(vfsStream::url('info_root/' . $info_file_name))
    );

    $this->assertEquals($expected_name, $extension->getName());
    $this->assertEquals($expected_project, $extension->getProject());
  }

}
