<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Composer\Json\JsonFile;

/**
 * Utility facade for JsonFile.
 */
class JsonFileUtility {

  /**
   * The JsonFile object we're working on.
   *
   * @var \Composer\Json\JsonFile
   */
  protected $jsonFile;

  /**
   * The contents of the JSON file.
   *
   * @var string
   */
  protected $jsonFileContents;

  /**
   * Construct a JsonFileUtility object.
   *
   * @param \Composer\Json\JsonFile $json_file
   *   The JsonFile object we'll use.
   */
  public function __construct(JsonFile $json_file) {
    $this->jsonFile = $json_file;
  }

  /**
   * Parsed JSON contents from the JSON file.
   *
   * @return mixed
   */
  protected function getContents() {
    if (empty($this->jsonFileContents)) {
      $this->jsonFileContents = $this->jsonFile->read();
    }
    return $this->jsonFileContents;
  }

  /**
   * Get the JsonFile object we're working with.
   *
   * @return \Composer\Json\JsonFile
   *   The JsonFile for this object.
   */
  public function getJsonFile() {
    return $this->jsonFile;
  }

  /**
   * Get both the require and require-dev sections of the composer.json file.
   *
   * @return string[][]
   *   Merged require and reqire-dev sections of the composer.json file.
   */
  public function getCombinedRequire() {
    return array_merge(
      $this->getRequire(),
      $this->getRequire(TRUE)
    );
  }

  /**
   * Get declared requirements from the composer.json file.
   *
   * @param bool $dev
   *   Should we get require-dev?
   *
   * @return string[][]
   *   The require or reqire-dev section of the composer.json file.
   */
  public function getRequire($dev = FALSE) {
    $key = 'require';
    if ($dev) {
      $key .= '-dev';
    }
    $json_contents = $this->getContents();
    return $json_contents[$key] ?? [];
  }

  public function getExtra() {
    $json_contents = $this->getContents();
    return $json_contents['extra'] ?? [];
  }

  public function getRepositories() {
    $json_contents = $this->getContents();
    return $json_contents['repositories'] ?? [];
  }

  public function getSortPackages() {
    $json_contents = $this->getContents();
    return $json_contents['config']['sort-packages'] ?? FALSE;
  }

}
