<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

class DrupalInspector {

  /**
   * Determines the version of Drupal core by looking at Drupal.php contents.
   *
   * @param string $file_contents
   *   The contents of Drupal.php.
   *
   * @return mixed|string
   */
  public static function determineDrupalCoreVersionFromDrupalPhp($file_contents) {
    /**
     * Matches:
     * const VERSION = '8.0.0';
     * const VERSION = '8.0.0-beta1';
     * const VERSION = '8.0.0-rc2';
     * const VERSION = '8.5.11';
     * const VERSION = '8.5.x-dev';
     * const VERSION = '8.6.11-dev';
     */
    preg_match('#(const VERSION = \')(\d\.\d\.(\d{1,}|x)(-(beta|alpha|rc)[0-9])?(-dev)?)\';#', $file_contents, $matches);
    if (array_key_exists(2, $matches)) {
      $version = $matches[2];

      // Matches 8.6.11-dev. This is not actually a valid semantic
      // version. We fix it to become 8.6.x-dev before returning.
      if (strstr($version, '-dev') !== false && substr_count($version, '.') == 2) {
        // Matches (core) version 8.6.11-dev.
        $version = str_replace('-dev', '', $version);
        $pos1 = strpos($version, '.');
        $pos2 = strpos($version, '.', $pos1 + 1);
        $version = substr($version, 0, $pos1 + $pos2) . 'x-dev';
      }

      return $version;
    }
  }

}
