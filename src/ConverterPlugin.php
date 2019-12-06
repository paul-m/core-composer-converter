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

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io) {
    // Necessary for API.
  }

  /**
   * {@inheritdoc}
   */
  public function getCapabilities() {
    return [
      ComposerCommandProvider::class => CommandProvider::class,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'post-install-cmd' => ['notifyUnreconciledExtensions'],
      'post-update-cmd' => ['notifyUnreconciledExtensions'],
    ];
  }

  /**
   * Tell the user about unreconciled Drupal extensions.
   *
   * @param \Composer\Script\Event $event
   */
  public static function notifyUnreconciledExtensions(Event $event) {
    $notifier = new UnreconciledNotifier($event->getComposer(), $event->getIO());
    $notifier->notify();
  }

}
