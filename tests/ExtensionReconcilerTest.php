<?php

namespace Drupal\Tests\Composer\Plugin\ComposerConverter;

use Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler;
use Drupal\Composer\Plugin\ComposerConverter\JsonFileUtility;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler
 * @group ComposerConverter
 */
class ExtensionReconcilerTest extends TestCase {

  public function provideGetSpecifiedExtensions() {
    return [
      'none' => [
        [], [], [],
      ],
      'required_project' => [
        ['drupal/project' => '^1.0'],
        ['drupal/project' => '^1.0'],
        ['project' => ['module', 'module2']],
      ],
      'required_extension' => [
        ['drupal/module2' => '^1.0'],
        ['drupal/module2' => '^1.0'],
        ['project' => ['module', 'module2']],
      ],
      'required_project_and_extension' => [
        [
          'drupal/module2' => '^1.0',
          'drupal/project2' => '^2.0',
        ],
        ['drupal/module2' => '^1.0', 'drupal/project2' => '^2.0'],
        [
          'project' => ['module', 'module2'],
          'project2' => ['module3', 'module4'],
        ],
      ],
    ];
  }

  /**
   * @dataProvider provideGetSpecifiedExtensions
   */
  public function testGetSpecifiedExtensions($expected, $require, $projects) {
    $from = $this->getMockBuilder(JsonFileUtility::class)
      ->disableOriginalConstructor()
      ->setMethods(['getRequire'])
      ->getMock();
    $from->expects($this->once())
      ->method('getRequire')
      ->willReturn($require);

    $reconiler = $this->getMockBuilder(ExtensionReconciler::class)
      // Make sure we don't run processPackages().
      ->setMethods(['processPackages'])
      ->setConstructorArgs([$from, ''])
      ->getMock();

    $ref_projects = new \ReflectionProperty($reconiler, 'projects');
    $ref_projects->setAccessible(TRUE);
    $ref_projects->setValue($reconiler, $projects);

    $this->assertSame($expected, $reconiler->getSpecifiedExtensions());
  }

  public function provideNeededPackagesExotic() {
    return [
      'empty' => [
        [],
        [],
        [],
      ],
      'unreconciled_exotic' => [
        ['foo' => 'Foo'],
        [],
        [
          'core' => [],
          'modules' => [
            'foo' => [
              'foo.info.yml' => "name: Foo\ntype: module",
            ],
          ],
        ],
      ],
      'specified_not_exotic' => [
        [],
        ['drupal/foo' => '^1.0'],
        [
          'core' => [],
          'modules' => [
            'foo' => [
              'foo.info.yml' => "name: Foo\nproject: foo_project",
            ],
          ],
        ],
      ],
      'specified_project_not_exotic' => [
        [],
        ['drupal/foo_project' => '^1.0'],
        [
          'core' => [],
          'modules' => [
            'foo' => [
              'foo.info.yml' => "name: Foo\nproject: foo_project",
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * @dataProvider provideNeededPackagesExotic
   */
  public function testProcessNeededPackagesExotic($expected, $require, $working_dir_filesystem) {
    // Mock our composer.json require section.
    $from = $this->getMockBuilder(JsonFileUtility::class)
      ->disableOriginalConstructor()
      ->setMethods(['getRequire'])
      ->getMock();
    $from->expects($this->any())
      ->method('getRequire')
      ->willReturn($require);

    // Virtual file system for our working dir.
    vfsStream::setup('working_dir', NULL, $working_dir_filesystem);

    $reconciler = new ExtensionReconciler($from, vfsStream::url('working_dir'));
    $this->assertSame($expected, $reconciler->getExoticPackages());
  }

  public function provideNeededPackagesUnreconciled() {
    return [
      'empty' => [
        [],
        [],
        [],
        FALSE,
      ],
      'unreconciled_exotic' => [
        [],
        [],
        [
          'core' => [],
          'modules' => [
            'foo' => [
              'foo.info.yml' => 'name: Foo',
            ],
          ],
        ],
        FALSE,
      ],
      'specified_not_exotic' => [
        [],
        ['drupal/foo' => '^1.0'],
        [
          'core' => [],
          'modules' => [
            'foo' => [
              'foo.info.yml' => "name: foo\nproject: foo_project",
            ],
          ],
        ],
        FALSE,
      ],
      'specified_project_not_exotic' => [
        [],
        ['drupal/foo_project' => '^1.0'],
        [
          'core' => [],
          'modules' => [
            'foo' => [
              'foo.info.yml' => "name: foo\nproject: foo_project",
            ],
          ],
        ],
        TRUE,
      ],
      'unspecified_not_exotic' => [
        ['foo' => 'drupal/foo'],
        [],
        [
          'core' => [],
          'modules' => [
            'foo' => [
              'foo.info.yml' => "name: foo\nproject: foo_project\ntype: module",
            ],
          ],
        ],
        FALSE,
      ],
      'unspecified_project_not_exotic' => [
        ['foo_project' => 'drupal/foo_project'],
        [],
        [
          'core' => [],
          'modules' => [
            'foo' => [
              'foo.info.yml' => "name: foo\nproject: foo_project\ntype: module",
            ],
          ],
        ],
        TRUE,
      ],
    ];
  }

  /**
   * @dataProvider provideNeededPackagesUnreconciled
   */
  public function testProcessNeededPackagesUnreconciled($expected, $require, $working_dir_filesystem, $prefer_projects) {
    // Mock our composer.json require section.
    $from = $this->getMockBuilder(JsonFileUtility::class)
      ->disableOriginalConstructor()
      ->setMethods(['getRequire'])
      ->getMock();
    $from->expects($this->any())
      ->method('getRequire')
      ->willReturn($require);

    // Virtual file system for our working dir.
    vfsStream::setup('working_dir', NULL, $working_dir_filesystem);

    // Create a reconciler.
    $reconiler = $this->getMockBuilder(ExtensionReconciler::class)
      ->setConstructorArgs([$from, vfsStream::url('working_dir'), $prefer_projects])
      ->getMock();

    $ref_process = new \ReflectionMethod($reconiler, 'processPackages');
    $ref_process->setAccessible(TRUE);

    $ref_process->invokeArgs($reconiler, [FALSE]);

    $ref_need = new \ReflectionProperty($reconiler, 'needThesePackages');
    $ref_need->setAccessible(TRUE);

    $this->assertSame($expected, $ref_need->getValue($reconiler));
  }

}
