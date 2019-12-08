<?php

namespace Drupal\Composer\Plugin\ComposerConverter\Command;

use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Semver\Semver;
use Drupal\Composer\Plugin\ComposerConverter\DrupalInspector;
use Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler;
use Drupal\Composer\Plugin\ComposerConverter\JsonFileUtility;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Performs a conversion of a composer.json file.
 */
class ConvertCommand extends ConvertCommandBase {

  /**
   * Contents of the composer.json file before we modified it.
   *
   * @var string
   */
  private $composerBackupContents;

  /**
   * Full path to the backup file we made of the original composer.json file.
   *
   * @var string
   */
  private $backupComposerJsonPath;
  private $rootComposerJsonPath;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('drupal:legacy-convert')
      ->setDescription('Convert your Drupal project to a Composer-based one.')
      ->setDefinition([
        new InputOption('package-name', NULL, InputOption::VALUE_REQUIRED, 'The new package name, to replace drupal/drupal.', 'drupal/legacy-project-converted'),
        new InputOption('dry-run', NULL, InputOption::VALUE_NONE, 'Display all the changes that would occur, without performing them.'),
        new InputOption('no-update', NULL, InputOption::VALUE_NONE, 'Perform conversion but does not perform update.'),
        new InputOption('sort-packages', NULL, InputOption::VALUE_NONE, 'Sorts packages when adding/updating a new dependency'),
        new InputOption('prefer-projects', NULL, InputOption::VALUE_NONE, 'When possible, use d.o project name instead of extension name.'),
      ])
      ->setHelp(
        <<<EOT
This command will change your composer.json file. By default it will also
try to peform a 'composer update' after it makes changes. It is highly
advisable to work on a backup installation, or to use git or other VCS so
you can undo the changes here. Never perform this operation on a production
site.
EOT
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output) {
    $style = new SymfonyStyle($input, $output);
    $output->writeln('<info>The following actions will be performed:</info>');
    $item_list = [
      'Add stuff to this list.',
    ];
    $style->listing($item_list);
    $helper = $this->getHelper('question');
    if (!$helper->ask($input, $output, new ConfirmationQuestion('Continue? ', FALSE))) {
      throw new \RuntimeException('User cancelled.', 1);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = $this->getIO();

    $working_dir = realpath($input->getOption('working-dir'));
    $this->rootComposerJsonPath = $working_dir . '/composer.json';

    if (!file_exists($this->rootComposerJsonPath)) {
      $io->write('<error>Unable to convert file because it does not exist: ' . $this->rootComposerJsonPath . '</error>');
      $io->write('Change to the root directory of your project, or use the --working-dir option.');
      return 1;
    }

    // Make a backup of the composer.json file.
    $io->write(' - Storing backup file...');
    $this->backupComposerJsonPath = $this->createBackup($working_dir, $this->rootComposerJsonPath);
    $this->composerBackupContents = file_get_contents($this->backupComposerJsonPath);

    // Replace composer.json with our template...
    $io->write(' - Creating new composer.json file...');

    // Come up with a Drupal core minor version. If we can't determine the actual
    // core version, we'll default to ^8.7 and warn the user that we're guessing.
    if (!$core_minor = $this->determineDrupalCoreVersion($this->locateDrupalClassFile($working_dir))) {
      $io->write(' - <info>Unable to determine core version. Defaulting to ^8.7.</info>');
      $core_minor = '^8.7';
    }
    // Put our info into the template.
    $template_contents = str_replace(
      '%core_minor%',
      $core_minor,
      file_get_contents(__DIR__ . '/../../templates/template.composer.json')
    );
    if (file_put_contents($this->rootComposerJsonPath, $template_contents) === FALSE) {
      $io->write('<error>Unable to replace composer.json file.</error>');
      $this->revertComposerFile(FALSE);
      return 1;
    }

    // Start manipulating the root composer.json.
    $backup_utility = new JsonFileUtility(new JsonFile($this->backupComposerJsonPath));
    $manipulator = new JsonManipulator(file_get_contents($this->rootComposerJsonPath));

    // Copy config for: Repositories, patches, config for drupal/core-* plugins.
    $io->write(' - Checking repositories...');
    $this->copyRepositories($backup_utility, $manipulator);
    $io->write(' - Checking extra configuration...');
    $this->copyExtra($backup_utility, $manipulator);

    // @todo: Configure drupal/core-composer-scaffold based on
    //        drupal-composer/drupal-scaffold config.',
    // Gather existing extension dependencies from the old composer.json file.
    $io->write(' - Moving existing Drupal extensions to new composer.json file...');
    $reconciler = new ExtensionReconciler($backup_utility, $working_dir);
    $extension_require = [
      'require' => $reconciler->getSpecifiedExtensions(),
      'require-dev' => $reconciler->getSpecifiedExtensions(TRUE),
    ];
    $sort_packages = $input->getOption('sort-packages') || (new JsonFileUtility(new JsonFile($this->rootComposerJsonPath)))->getSortPackages();
    foreach ($extension_require as $requires => $dependencies) {
      foreach ($dependencies as $package_name => $constraint) {
        $manipulator->addLink($requires, $package_name, $constraint, $sort_packages);
      }
    }

    // Finally write out our new composer.json content and send it off to
    // drupal:reconcile-extensions.
    file_put_contents($this->rootComposerJsonPath, $manipulator->getContents());

    /* @var $reconcile_command \Composer\Command\BaseCommand */
    $reconcile_command = $this->getApplication()->find('drupal:reconcile-extensions');
    $return_code = $reconcile_command->run(
      new ArrayInput([
        '--dry-run' => $input->getOption('dry-run'),
        '--no-update' => $input->getOption('no-update'),
        '--sort-packages' => $input->getOption('sort-packages'),
        '--prefer-projects' => $input->getOption('prefer-projects'),
        '--no-interaction' => TRUE,
        ]),
      $output
    );
  }

  /**
   * Revert our changes to the composer.json file.
   *
   * @param mixed $hardExit
   */
  public function revertComposerFile($hardExit = TRUE) {
    $io = $this->getIO();

    $io->writeError("\n" . '<error>Conversion failed, reverting ' . $this->rootComposerJsonPath . ' to its original contents.</error>');
    file_put_contents($this->rootComposerJsonPath, $this->composerBackupContents);

    if ($hardExit) {
      exit(1);
    }
  }

  /**
   *
   * @param string $working_dir
   *   Composer working-dir.
   * @param string $root_file_path
   *   Path to the root Composer.json file we wish to backup.
   *
   * @return string
   *   Full path to the backup file.
   *
   * @throws \Excecption
   *   Thrown when the backup process fails.
   *
   * @todo Use a datetime/hash type filename for the backup so we don't overwrite the
   *       previous one.
   */
  protected function createBackup($working_dir, $root_file_path) {
    $backup_path = $working_dir . '/backup.composer.json';
    if (!copy($root_file_path, $backup_path)) {
      throw new \Excecption('Unable to back up to ' . $backup_path);
    }
    return $backup_path;
  }

  /**
   * Copy known-good extra configurations from old to new.
   *
   * @param \Composer\Json\JsonFile $from
   *   The old composer.json file.
   * @param string $to
   *   Location of the new composer.json file.
   */
  protected function copyExtra(JsonFileUtility $from, JsonManipulator $to) {
    $extras_to_copy = [
      'drupal-core-project-message',
      'drupal-scaffold',
      'installer-paths',
      // Merge plugin.
      'merge-plugin',
      // Patch plugin.
      'patches',
      'patches-file',
      'patches-ignore',
      'enable-patching',
    ];
    $from_extra = $from->getExtra();

    foreach (array_keys($from_extra) as $extra) {
      if (!in_array($extra, $extras_to_copy)) {
        unset($from_extra[$extra]);
      }
    }

    foreach ($from_extra as $name => $value) {
      $to->addSubNode('extra', $name, $value);
    }
  }

  /**
   * Ensure that new root has Drupal Composer facade repo.
   */
  protected function copyRepositories(JsonFileUtility $from, JsonManipulator $to) {
    $drupal_facade = 'https://packages.drupal.org/8';
    $from_repositories = $from->getRepositories();

    // If we already have the Drupal facade then we don't need to add it.
    $needs_facade = TRUE;
    foreach ($from_repositories as $repository) {
      if (($repository['url'] ?: NULL) === $drupal_facade) {
        $needs_facade = FALSE;
      }
    }

    if ($needs_facade) {
      $from_repositories[] = [
        'type' => 'composer',
        'url' => $drupal_facade,
      ];
    }

    $to->addMainKey('repositories', $from_repositories);
  }

  /**
   * Get the Drupal core version from \Drupal::VERSION if possible.
   *
   * @param string|mixed $drupal_class_file
   *   Full path to the \Drupal class file. This is usually located in
   *   core/lib/Drupal.php. If empty or FALSE, a default value will be returned.
   *
   * @return string
   *   A semver constraint for the current Drupal core minor version. If none can be
   *   determined, defaults to '^8.7'.
   */
  protected function determineDrupalCoreVersion($drupal_class_file) {
    // Default to a reasonable previous minor version. When the user updates, they'll
    // get the newest. This is not optimal, but might be required if, for instance,
    // they have never installed this project and don't have a \Drupal class to load.
    $drupal_core_constraint = '^8.7';

    if ($drupal_class_file) {
      $core_version = DrupalInspector::determineDrupalCoreVersionFromDrupalPhp(file_get_contents($drupal_class_file));
      if (Semver::satisfiedBy([$core_version], "*")) {
        // Use major and minor. We know major and minor are present because this
        // version comes from \Drupal::VERSION.
        if (preg_match('/^(\d+).(\d+)./', $core_version, $matches)) {
          $drupal_core_constraint = '^' . $matches[1] . '.' . $matches[2];
        }
      }
    }
    return $drupal_core_constraint;
  }

  /**
   * Locate the \Drupal class file for the codebase.
   *
   * @param string $working_dir
   *   The full path to the Composer working directory.
   *
   * @return bool|string
   *   The full path to the \Drupal class file, or FALSE if no file could be found.
   */
  protected function locateDrupalClassFile($working_dir) {
    // Basic drupal/drupal or legacy file layout.
    $drupal_class = $working_dir . '/core/lib/Drupal.php';
    if (file_exists($drupal_class)) {
      return $drupal_class;
    }
    // Both drupal-composer/drupal-scaffold and drupal/core-composer-scaffold default
    // to a docroot of 'web'. If we find it there, we're done.
    $drupal_class = $working_dir . '/web/core/lib/Drupal.php';
    if (file_exists($drupal_class)) {
      return $drupal_class;
    }
    // Try with drupal/core-composer-scaffold configuration.
    $extra = (new JsonFileUtility(new JsonFile($this->backupComposerJsonPath)))->getExtra();
    $drupal_class = realpath($working_dir . ($extra['drupal-scaffold']['locations']['web-root'] ?? '') . '/core/lib/Drupal.php');
    if (file_exists($drupal_class)) {
      return $drupal_class;
    }
    return FALSE;
  }

}
