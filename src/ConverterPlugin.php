<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;


class ConverterPlugin implements PluginInterface, Capable {

  public function activate(Composer $composer, IOInterface $io) {
    // No-op, necessary for interface.
  }

  public function getCapabilities() {
    return [
      ComposerCommandProvider::class => CommandProvider::class,
    ];
  }

}
