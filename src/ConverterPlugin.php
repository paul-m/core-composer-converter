<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;

/**
 * Point of entry for Composer's plugin API.
 */
class ConverterPlugin implements Capable, EventSubscriberInterface, PluginInterface {

  public function activate(Composer $composer, IOInterface $io) {
    // Necessary for API.
  }

  public function getCapabilities() {
    return [
      ComposerCommandProvider::class => CommandProvider::class,
    ];
  }

  public static function getSubscribedEvents(): array {
    return [
      'post-install-cmd' => ['notifyUnreconciledExtensions'],
      'post-update-cmd' => ['notifyUnreconciledExtensions'],
    ];
  }

  public static function notifyUnreconciledExtensions(Event $event) {
    $notifier = new UnreconciledNotifier($event->getComposer(), $event->getIO());
    $notifier->notify();
  }

}
