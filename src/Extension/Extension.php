<?php

namespace Drupal\Composer\Plugin\ComposerConverter\Extension;

use Drupal\Composer\Plugin\ComposerConverter\DrupalInspector;
use Symfony\Component\Yaml\Yaml;

/**
 * Data class for extensions.
 */
class Extension {

  /**
   * Object representing the *.info.yml (or *.info) file.
   *
   * @var \SplFileInfo
   */
  protected $infoFile;

  /**
   * The machine name of the extension.
   *
   * @var string
   */
  protected $machineName;

  /**
   * The parsed YAML from the info file.
   *
   * @var string[]
   */
  protected $parsedInfo;

  public function __construct(\SplFileInfo $info_file) {
    $this->infoFile = $info_file;
    if ($info_file->getExtension() == 'info') {
      $this->machineName = basename($info_file->getPathname(), '.info');
      return;
    }
    $this->machineName = basename($info_file->getPathname(), '.info.yml');
  }

  public function getInfoFile() {
    return $this->infoFile;
  }

  public function getMachineName() {
    return $this->machineName;
  }

  protected function getParsedInfo() {
    if (empty($this->parsedInfo)) {
      $pathname = $this->getInfoFile()->getPathname();
      // Account for D7-style info files.
      if ($this->getInfoFile()->getExtension() == 'info') {
        $this->parsedInfo = ExtensionRepository::drupalParseInfoFormat(file_get_contents($pathname));
      }
      else {
        $this->parsedInfo = Yaml::parseFile($pathname);
      }
    }
    return $this->parsedInfo;
  }

  protected function getInfo($key, $default = NULL) {
    $info = $this->getParsedInfo();
    return $info[$key] ?? $default;
  }

  public function getName() {
    return $this->getInfo('name', $this->machineName);
  }

  public function getProject($default = NULL) {
    return $this->getInfo('project', $default);
  }

  public function getVersion() {
    return $this->getInfo('version');
  }

  public function getSemanticVersion() {
    return DrupalInspector::getSemanticVersion($this->getVersion());
  }

}
