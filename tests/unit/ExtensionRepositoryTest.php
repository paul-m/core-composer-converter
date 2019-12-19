<?php

namespace Drupal\Tests\Composer\Plugin\ComposerConverter;

use Drupal\Composer\Plugin\ComposerConverter\Extension\ExtensionRepository;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;

/**
 * Tests the Extension class.
 *
 * @coversDefaultClass Drupal\Composer\Plugin\ComposerConverter\Extension\ExtensionRepository
 * @group ComposerConverter
 */
class ExtensionRepositoryTest extends TestCase {

  /**
   * @covers ::create
   */
  public function testCreate() {
    vfsStream::setup('info_root', NULL, [
      'd7module' => [
        'd7module.info' => 'name = D7-style info file',
      ],
      'd8module' => [
        'd8module.info.yml' => "name: D8-style info file\ntype: module",
      ],
    ]);

    $repo = ExtensionRepository::create(vfsStream::url('info_root'));

    $extensions = $repo->getExtensions();

    $this->assertArrayHasKey('d8module', $extensions);
    $this->assertArrayNotHasKey('d7module', $extensions);
  }

}
