<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;

/**
 * Tell the user if there are unreconciled extension in their Drupal filesystem.
 */
class UnreconciledNotifier {

  /**
   * The Compsoer object.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * The IO object.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  public function __construct(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  public function notify() {
    $working_dir = realpath($this->composer->getConfig()->get('working-dir'));
    $root_package_path = $working_dir . '/composer.json';
    $from_utility = new JsonFileUtility(new JsonFile($root_package_path));
    $reconciler = new ExtensionReconciler($from_utility, $working_dir, true);

    if ($reconciler->getUnreconciledPackages()) {
      $this->io->write("<info>This project has extensions on the file system which are not reflected in the composer.json file. Run 'composer drupal:reconcile-extensions --help' to fix this.</info>");
    }
    if ($reconciler->getExoticPackages()) {
      $this->io->write("<info>This project has extensions on the file system which might require manual updating. Read docs here: http://write.these.docs/</info>");
    }
  }

}
