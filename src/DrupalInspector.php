<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

/**
 * Utilities for dealing with a Drupal codebase.
 *
 * Mostly copied from grasmash/composerize-drupal.
 */
class DrupalInspector {

  /**
   * Generates a semantic version for a Drupal project.
   *
   * 3.0
   * 3.0-alpha1
   * 3.12-beta2
   * 4.0-rc12
   * 3.12
   * 1.0-unstable3
   * 0.1-rc2
   * 2.10-rc2
   *
   * {major}.{minor}.0-{stability}{#}
   *
   * @return string
   */
  public static function getSemanticVersion($drupal_version) {
    // Strip the 8.x prefix from the version.
    $version = preg_replace('/^8\.x-/', null, $drupal_version);

    if (preg_match('/-dev$/', $version)) {
      return preg_replace('/^(\d).+-dev$/', '$1.x-dev', $version);
    }

    $matches = [];
    preg_match('/^(\d{1,2})\.(\d{0,2})(\-(alpha|beta|rc|unstable)\d{1,2})?$/i', $version, $matches);
    $version = false;
    if (!empty($matches)) {
      $version = "{$matches[1]}.{$matches[2]}.0";
      if (array_key_exists(3, $matches)) {
        $version .= $matches[3];
      }
    }

    // Reject 'unstable'.

    return $version;
  }

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
