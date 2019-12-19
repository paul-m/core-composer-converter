<?php

namespace Drupal\Composer\Plugin\ComposerConverter\Command;

use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Installer;
use Composer\IO\IOInterface;
use Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler;
use Drupal\Composer\Plugin\ComposerConverter\JsonFileUtility;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class ExtensionReconcileCommand extends ConvertCommandBase {

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('drupal:reconcile-extensions')
      ->setDescription('Declare your extensions in composer.json.')
      ->setDefinition([
        new InputOption('dry-run', NULL, InputOption::VALUE_NONE, 'Display all the changes that would occur, without performing them.'),
        new InputOption('prefer-projects', NULL, InputOption::VALUE_NONE, 'When possible, use d.o project name instead of extension name.'),
        // Options from Composer\Command\RequireCommand.
        new InputOption('dev', NULL, InputOption::VALUE_NONE, 'Add requirement to require-dev.'),
        new InputOption('prefer-source', NULL, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
        new InputOption('prefer-dist', NULL, InputOption::VALUE_NONE, 'Forces installation from package dist even for dev versions.'),
        new InputOption('no-progress', NULL, InputOption::VALUE_NONE, 'Do not output download progress.'),
        new InputOption('no-suggest', NULL, InputOption::VALUE_NONE, 'Do not show package suggestions.'),
        new InputOption('no-update', NULL, InputOption::VALUE_NONE, 'Disables the automatic update of the dependencies.'),
        new InputOption('no-scripts', NULL, InputOption::VALUE_NONE, 'Skips the execution of all scripts defined in composer.json file.'),
        new InputOption('update-no-dev', NULL, InputOption::VALUE_NONE, 'Run the dependency update with the --no-dev option.'),
        new InputOption('update-with-dependencies', NULL, InputOption::VALUE_NONE, 'Allows inherited dependencies to be updated, except those that are root requirements.'),
        new InputOption('update-with-all-dependencies', NULL, InputOption::VALUE_NONE, 'Allows all inherited dependencies to be updated, including those that are root requirements.'),
        new InputOption('ignore-platform-reqs', NULL, InputOption::VALUE_NONE, 'Ignore platform requirements (php & ext- packages).'),
        new InputOption('prefer-stable', NULL, InputOption::VALUE_NONE, 'Prefer stable versions of dependencies.'),
        new InputOption('prefer-lowest', NULL, InputOption::VALUE_NONE, 'Prefer lowest versions of dependencies.'),
        new InputOption('sort-packages', NULL, InputOption::VALUE_NONE, 'Sorts packages when adding/updating a new dependency'),
        new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump'),
        new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only. Implicitly enables `--optimize-autoloader`.'),
        new InputOption('apcu-autoloader', NULL, InputOption::VALUE_NONE, 'Use APCu to cache found/not-found classes.'),
      ])
      ->setHelp(
        <<<EOT
This command performs the following actions, which might be destructive:
 * Determine if there are any extension on the file system which are not
   represented in the root composer.json.
 * Declare these extensions within composer.json so that you can use Composer
   to manage them.
 * Remove the existing extensions from the file system.

Run the command with option --dry-run to rehearse the process.
EOT
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output) {
    if (!$input->getOption('no-interaction')) {
      $output->writeln('<info>Warning</info>:');
      $output->writeln($this->getHelp());
      $helper = $this->getHelper('question');
      if (!$helper->ask($input, $output, new ConfirmationQuestion('Continue? ', FALSE))) {
        throw new \RuntimeException('User cancelled.', 1);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = $this->getIO();
    $dry_run = $input->getOption('dry-run');
    $working_dir = realpath($input->getOption('working-dir'));

    $rootComposerJsonPath = $working_dir . '/composer.json';

    // Make a reconciler for our root composer.json.
    $io->write(' - Scanning the file system for extensions not in the composer.json file...');
    $reconciler = new ExtensionReconciler(
      new JsonFileUtility(new JsonFile($rootComposerJsonPath)),
      $working_dir,
      $input->getOption('prefer-projects')
    );
    // Get our list of packages for our unreconciled extensions.
    $add_packages = $reconciler->getUnreconciledPackages();

    // Add all the packages we need. We have to do some basic solving here, much
    // like composer require does.
    if ($add_packages) {
      // Use the factory to create a new Composer object, so that we can use
      // changes in our root composer.json.
      $composer = Factory::create($io, $rootComposerJsonPath);

      // Populate $this->repos so that our superclass can use it.
      $this->repos = new CompositeRepository(array_merge(
          [new PlatformRepository([], $composer->getConfig()->get('platform') ?: [])],
          $composer->getRepositoryManager()->getRepositories()
      ));

      // Figure out prefer-stable.
      if ($composer->getPackage()->getPreferStable()) {
        $preferred_stability = 'stable';
      }
      else {
        $preferred_stability = $composer->getPackage()->getMinimumStability();
      }
      $php_version = $this->repos->findPackage('php', '*')->getPrettyVersion();

      // Do some constraint resolution.
      $requirements = NULL;
      if ($requirements = $this->determineRequirements($input, $output, $add_packages, $php_version, $preferred_stability)) {
        if ($dry_run) {
          $io->write(' - (Dry run) Add these packages: <info>' . implode('</info>, <info>', $requirements) . '</info>');
        }
        else {
          // Add our new dependencies.
          $manipulator = new JsonManipulator(file_get_contents($rootComposerJsonPath));
          $sort_packages = $input->getOption('sort-packages') || (new JsonFileUtility(new JsonFile($rootComposerJsonPath)))->getSortPackages();
          foreach ($this->formatRequirements($requirements) as $package => $constraint) {
            $manipulator->addLink('require', $package, $constraint, $sort_packages);
          }
          file_put_contents($rootComposerJsonPath, $manipulator->getContents());
        }
      }

      // Remove the existing extensions from the file system.
      if ($dry_run) {
        $io->write(' - (Dry run) Remove these extensions from the file system: <info>' . implode('</info>, <info>', array_keys($add_packages)) . '</info>');
      }
      else {
        $io->write(' - Removing these extensions from the file system: <info>' . implode('</info>, <info>', array_keys($add_packages)) . '</info>');
        $extensions = $reconciler->getAllUnreconciledExtensions();
        $remove_paths = [];
        foreach (array_keys($add_packages) as $machine_name) {
          $remove_paths[] = dirname($extensions[$machine_name]->getInfoFile());
        }
        (new Filesystem())->remove($remove_paths);
      }

      // Perform the update.
      if ($requirements && (!$dry_run || !$input->getOption('no-update'))) {
        try {
          return $this->doUpdate($input, $output, $io, $requirements);
        }
        catch (\Exception $e) {
          // $this->revertComposerFile(false);
          throw $e;
        }
      }
    }

    // Alert the user that they have 'exotic' unreconciled extensions.
    if ($exotic = $reconciler->getExoticPackages()) {
      $style = new SymfonyStyle($input, $output);
      $io->write(' - Discovered extensions which are not in the original composer.json, and which do not have drupal.org projects. These extensions will need to be added manually if you wish to manage them through Composer:');
      $style->listing($exotic);
    }

    // Discover orphaned legacy extensions with *.info files.
    // @todo Figure out if we really want to do this.
    /*    $legacy_extensions = ExtensionRepository::create($rootComposerJsonPath, ExtensionRepository::findInfoFiles($rootComposerJsonPath));
      if ($extensions = $legacy_extensions->getExtensions()) {
      $rootPathLength = strlen(dirname($rootComposerJsonPath));
      $legacy_paths = [' - Discovered legacy extensions with *.info files:'];
      foreach ($extensions as $machine_name => $extension) {
      $legacy_paths[] = '   ' . substr($extension->getInfoFile()->getPathname(), $rootPathLength);
      }
      $io->write($legacy_paths);
      } */

    $io->write(['<info>Finished!</info>', '']);
  }

  /**
   * Perform the update.
   *
   * Swiped from Composer\Command\RequireCommand.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   * @param \Drupal\Composer\Plugin\ComposerConverter\Command\IOInterface $io
   * @param string[] $requirements
   *   An array of requirements as package name and version constraint, such as
   *   'drupal/examples ^1.0'.
   */
  private function doUpdate(InputInterface $input, OutputInterface $output, IOInterface $io, array $requirements) {
    // Update packages
    $this->resetComposer();
    $composer = $this->getComposer(TRUE, $input->getOption('no-plugins'));
    $composer->getDownloadManager()->setOutputProgress(!$input->getOption('no-progress'));
    $updateDevMode = !$input->getOption('update-no-dev');
    $optimize = $input->getOption('optimize-autoloader') || $composer->getConfig()->get('optimize-autoloader');
    $authoritative = $input->getOption('classmap-authoritative') || $composer->getConfig()->get('classmap-authoritative');
    $apcu = $input->getOption('apcu-autoloader') || $composer->getConfig()->get('apcu-autoloader');

    $install = Installer::create($io, $composer);
    $install
      ->setVerbose($input->getOption('verbose'))
      ->setPreferSource($input->getOption('prefer-source'))
      ->setPreferDist($input->getOption('prefer-dist'))
      ->setDevMode($updateDevMode)
      ->setRunScripts(!$input->getOption('no-scripts'))
      ->setSkipSuggest($input->getOption('no-suggest'))
      ->setOptimizeAutoloader($optimize)
      ->setClassMapAuthoritative($authoritative)
      ->setApcuAutoloader($apcu)
      ->setUpdate(TRUE)
      ->setUpdateWhitelist(array_keys($requirements))
      ->setWhitelistTransitiveDependencies($input->getOption('update-with-dependencies'))
      ->setWhitelistAllDependencies($input->getOption('update-with-all-dependencies'))
      ->setIgnorePlatformRequirements($input->getOption('ignore-platform-reqs'))
      ->setPreferStable($input->getOption('prefer-stable'))
      ->setPreferLowest($input->getOption('prefer-lowest'));

    $status = $install->run();
    if ($status !== 0) {
      // $this->revertComposerFile(FALSE);
    }

    return $status;
  }

}
