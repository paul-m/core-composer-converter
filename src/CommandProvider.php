<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Drupal\Composer\Plugin\ComposerConverter\Command\ConvertCommand;
use Drupal\Composer\Plugin\ComposerConverter\Command\ExtensionReconcileCommand;

/**
 * Command provider API.
 */
class CommandProvider implements CommandProviderCapability {

  /**
   * {@inheritdoc}
   */
  public function getCommands() {
    return [
      new ConvertCommand(),
      new ExtensionReconcileCommand(),
    ];
  }

}
