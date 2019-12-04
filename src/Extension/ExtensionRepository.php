<?php

namespace Drupal\Composer\Plugin\ComposerConverter\Extension;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Given a place to search, find all the Drupal extensions in the filesystem.
 */
class ExtensionRepository {

  /**
   * All the extensions keyed by their machine name.
   *
   * @var \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension[]
   */
  protected $extensions;

  /**
   * Arrays of extensions keyed by project name.
   *
   * @var \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension[][]
   */
  protected $projectExtensions;

  /**
   * Arrays of extensions keyed by project name.
   *
   * @var \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension[][]
   */
  protected $exoticExtensions = NULL;

  /**
   *
   * @param string $root_directory
   *   Full path to the root directory where we start searching.
   * @param \Iterator $extension_iterator
   *   (optional) An iterator which supplies \SplFileInfo objects for *.info.yml
   *   files. Defaults to using the iterator supplied by static::findInfoFiles().
   *
   * @return \static
   *   An extension collection object.
   */
  public static function create($root_directory, \Iterator $extension_iterator = NULL) {
    if ($extension_iterator === NULL) {
      $extension_iterator = static::findInfoFiles($root_directory);
    }
    $extensions = [];
    /* @var $file \SplFileInfo */
    foreach ($extension_iterator as $file) {
      $e = new Extension($file);
      $extensions[$e->getMachineName()] = $e;
    }
    return new static($extensions);
  }

  /**
   * Constructs an extension collection object.
   *
   * @param \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension[] $extensions
   *   An array of extension objects, keyed by their machine name.
   */
  public function __construct($extensions) {
    $this->extensions = $extensions;
  }

  /**
   * Get all the discovered extensions.
   *
   * @return \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension[]
   *   Array of all the discovered extensions, keyed by their machine name.
   */
  public function getExtensions() {
    return $this->extensions;
  }

  /**
   *
   * @param string $machine_name
   *   The machine name of the extension.
   *
   * @return string
   *   Path to the containing directory of the extension.
   */
  public function getPathForExtension($machine_name) {
    /* @var $extension \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension */
    $extension = $this->extensions[$machine_name] ?? NULL;
    if (!$extension) {
      return NULL;
    }
    return $extension->getInfoFile()->getPath();
  }

  /**
   *
   * @param string $project_name
   *   Drupal.org project name.
   *
   * @return \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension[]
   *   The extensions which belong to the project.
   */
  public function getExtensionsForProject($project_name) {
    if (!$this->projectExtensions) {
      $this->sortProjectExtensions();
    }
    return $this->projectExtensions[$project_name] ?? [];
  }

  /**
   *
   * @return string[]
   *   All the project names.
   */
  public function getProjectNames() {
    if (!$this->projectExtensions) {
      $this->sortProjectExtensions();
    }
    return array_keys($this->projectExtensions);
  }

  /**
   * Get all the extensions which do not have an associated project.
   *
   * @return \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension[]
   *   An array of extensions with no project field. Array key is extension machine
   *   name.
   */
  public function getExoticExtensions() {
    if ($this->exoticExtensions === NULL) {
      $this->sortProjectExtensions();
    }
    return $this->exoticExtensions;
  }

  /**
   * Sort extensions into projects.
   */
  protected function sortProjectExtensions() {
    $this->projectExtensions = [];
    $this->exoticExtensions = [];
    /* @var $extension \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension */
    foreach ($this->extensions as $machine_name => $extension) {
      if ($project_name = $extension->getProject()) {
        $this->projectExtensions[$project_name][] = $extension;
      }
      else {
        $this->exoticExtensions[$extension->getMachineName()] = $extension;
      }
    }
  }

  /**
   * Find all the info files in the codebase.
   *
   * Exclude hidden extensions and those in the 'testing' package.
   *
   * @param string $root
   *
   * @return \Symfony\Component\Finder\Finder
   *   Finder object ready for iteration.
   */
  protected static function findInfoFiles($root) {
    // Discover extensions.
    $finder = new Finder();
    $finder->in($root)
      ->exclude(['core', 'vendor'])
      ->name('*.info.yml')
      // Test paths can include unmarked test extensions, especially themes.
      ->notPath('tests')
      ->filter(function ($info_file) {
        $info = Yaml::parseFile($info_file);
        if (isset($info['hidden']) && $info['hidden'] === TRUE) {
          return FALSE;
        }
        if (isset($info['package']) && strtolower($info['package']) == 'testing') {
          return FALSE;
        }
      });
    return $finder;
  }

}
