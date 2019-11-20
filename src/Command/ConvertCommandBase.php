<?php

namespace Drupal\Composer\Plugin\ComposerConverter\Command;

use Composer\DependencyResolver\Pool;
use Composer\Factory;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Composer\Command\BaseCommand;

/**
 * Borrows heavily from \Composer\Command\InitCommand.
 */
class ConvertCommandBase extends BaseCommand {

  /** @var CompositeRepository */
  protected $repos;

  /** @var array */
  private $gitConfig;

  /** @var Pool[] */
  private $pools;
  private $isSubCommand = FALSE;

  public function setSubCommand($subcommand = TRUE) {
    $this->isSubCommand = $subcommand;
  }

  public function isSubCommand() {
    return $this->isSubCommand;
  }

  /**
   * @private
   * @param  string $author
   * @return array
   */
  public function parseAuthorString($author) {
    if (preg_match('/^(?P<name>[- .,\p{L}\p{N}\p{Mn}\'’"()]+) <(?P<email>.+?)>$/u', $author, $match)) {
      if ($this->isValidEmail($match['email'])) {
        return array(
          'name' => trim($match['name']),
          'email' => $match['email'],
        );
      }
    }

    throw new \InvalidArgumentException(
      'Invalid author string.  Must be in the format: ' .
      'John Smith <john@example.com>'
    );
  }

  protected function findPackages($name) {
    return $this->getRepos()->search($name);
  }

  protected function getRepos() {
    if (!$this->repos) {
      $this->repos = new CompositeRepository(array_merge(
          array(new PlatformRepository),
          RepositoryFactory::defaultRepos($this->getIO())
      ));
    }

    return $this->repos;
  }

  protected function determineRequirements(InputInterface $input, OutputInterface $output, $requires = array(), $phpVersion = null, $preferredStability = 'stable', $checkProvidedVersions = true) {
    if ($requires) {
      $requires = $this->normalizeRequirements($requires);
      $result = array();
      $io = $this->getIO();

      foreach ($requires as $requirement) {
        if (!isset($requirement['version'])) {
          // determine the best version automatically
          list($name, $version) = $this->findBestVersionAndNameForPackage($input, $requirement['name'], $phpVersion, $preferredStability);
          $requirement['version'] = $version;

          // replace package name from packagist.org
          $requirement['name'] = $name;

          $io->writeError(sprintf(
              'Using version <info>%s</info> for <info>%s</info>',
              $requirement['version'],
              $requirement['name']
          ));
        }
        else {
          // check that the specified version/constraint exists before we proceed
          list($name, $version) = $this->findBestVersionAndNameForPackage($input, $requirement['name'], $phpVersion, $preferredStability, $checkProvidedVersions ? $requirement['version'] : null, 'dev');

          // replace package name from packagist.org
          $requirement['name'] = $name;
        }

        $result[] = $requirement['name'] . ' ' . $requirement['version'];
      }

      return $result;
    }

    $versionParser = new VersionParser();
    $io = $this->getIO();
    while (null !== $package = $io->ask('Search for a package: ')) {
      $matches = $this->findPackages($package);

      if (count($matches)) {
        $exactMatch = null;
        $choices = array();
        foreach ($matches as $position => $foundPackage) {
          $abandoned = '';
          if (isset($foundPackage['abandoned'])) {
            if (is_string($foundPackage['abandoned'])) {
              $replacement = sprintf('Use %s instead', $foundPackage['abandoned']);
            }
            else {
              $replacement = 'No replacement was suggested';
            }
            $abandoned = sprintf('<warning>Abandoned. %s.</warning>', $replacement);
          }

          $choices[] = sprintf(' <info>%5s</info> %s %s', "[$position]", $foundPackage['name'], $abandoned);
          if ($foundPackage['name'] === $package) {
            $exactMatch = true;
            break;
          }
        }

        // no match, prompt which to pick
        if (!$exactMatch) {
          $io->writeError(array(
            '',
            sprintf('Found <info>%s</info> packages matching <info>%s</info>', count($matches), $package),
            '',
          ));

          $io->writeError($choices);
          $io->writeError('');

          $validator = function ($selection) use ($matches, $versionParser) {
            if ('' === $selection) {
              return false;
            }

            if (is_numeric($selection) && isset($matches[(int) $selection])) {
              $package = $matches[(int) $selection];

              return $package['name'];
            }

            if (preg_match('{^\s*(?P<name>[\S/]+)(?:\s+(?P<version>\S+))?\s*$}', $selection, $packageMatches)) {
              if (isset($packageMatches['version'])) {
                // parsing `acme/example ~2.3`
                // validate version constraint
                $versionParser->parseConstraints($packageMatches['version']);

                return $packageMatches['name'] . ' ' . $packageMatches['version'];
              }

              // parsing `acme/example`
              return $packageMatches['name'];
            }

            throw new \Exception('Not a valid selection');
          };

          $package = $io->askAndValidate(
            'Enter package # to add, or the complete package name if it is not listed: ',
            $validator,
            3,
            false
          );
        }

        // no constraint yet, determine the best version automatically
        if (false !== $package && false === strpos($package, ' ')) {
          $validator = function ($input) {
            $input = trim($input);

            return $input ?: false;
          };

          $constraint = $io->askAndValidate(
            'Enter the version constraint to require (or leave blank to use the latest version): ',
            $validator,
            3,
            false
          );

          if (false === $constraint) {
            list($name, $constraint) = $this->findBestVersionAndNameForPackage($input, $package, $phpVersion, $preferredStability);

            $io->writeError(sprintf(
                'Using version <info>%s</info> for <info>%s</info>',
                $constraint,
                $package
            ));
          }

          $package .= ' ' . $constraint;
        }

        if (false !== $package) {
          $requires[] = $package;
        }
      }
    }

    return $requires;
  }

  protected function formatAuthors($author) {
    return array($this->parseAuthorString($author));
  }

  protected function formatRequirements(array $requirements) {
    $requires = array();
    $requirements = $this->normalizeRequirements($requirements);
    foreach ($requirements as $requirement) {
      $requires[$requirement['name']] = $requirement['version'];
    }

    return $requires;
  }

  protected function getGitConfig() {
    if (null !== $this->gitConfig) {
      return $this->gitConfig;
    }

    $finder = new ExecutableFinder();
    $gitBin = $finder->find('git');

    // TODO in v3 always call with an array
    if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
      $cmd = new Process(array($gitBin, 'config', '-l'));
    }
    else {
      $cmd = new Process(sprintf('%s config -l', ProcessExecutor::escape($gitBin)));
    }
    $cmd->run();

    if ($cmd->isSuccessful()) {
      $this->gitConfig = array();
      preg_match_all('{^([^=]+)=(.*)$}m', $cmd->getOutput(), $matches, PREG_SET_ORDER);
      foreach ($matches as $match) {
        $this->gitConfig[$match[1]] = $match[2];
      }

      return $this->gitConfig;
    }

    return $this->gitConfig = array();
  }

  /**
   * Checks the local .gitignore file for the Composer vendor directory.
   *
   * Tested patterns include:
   *  "/$vendor"
   *  "$vendor"
   *  "$vendor/"
   *  "/$vendor/"
   *  "/$vendor/*"
   *  "$vendor/*"
   *
   * @param string $ignoreFile
   * @param string $vendor
   *
   * @return bool
   */
  protected function hasVendorIgnore($ignoreFile, $vendor = 'vendor') {
    if (!file_exists($ignoreFile)) {
      return false;
    }

    $pattern = sprintf('{^/?%s(/\*?)?$}', preg_quote($vendor));

    $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $line) {
      if (preg_match($pattern, $line)) {
        return true;
      }
    }

    return false;
  }

  protected function normalizeRequirements(array $requirements) {
    $parser = new VersionParser();

    return $parser->parseNameVersionPairs($requirements);
  }

  protected function addVendorIgnore($ignoreFile, $vendor = '/vendor/') {
    $contents = "";
    if (file_exists($ignoreFile)) {
      $contents = file_get_contents($ignoreFile);

      if ("\n" !== substr($contents, 0, -1)) {
        $contents .= "\n";
      }
    }

    file_put_contents($ignoreFile, $contents . $vendor . "\n");
  }

  protected function isValidEmail($email) {
    // assume it's valid if we can't validate it
    if (!function_exists('filter_var')) {
      return true;
    }

    // php <5.3.3 has a very broken email validator, so bypass checks
    if (PHP_VERSION_ID < 50303) {
      return true;
    }

    return false !== filter_var($email, FILTER_VALIDATE_EMAIL);
  }

  private function getPool(InputInterface $input, $minimumStability = null) {
    $key = $minimumStability ?: 'default';

    if (!isset($this->pools[$key])) {
      $this->pools[$key] = $pool = new Pool($minimumStability ?: $this->getMinimumStability($input));
      $pool->addRepository($this->getRepos());
    }

    return $this->pools[$key];
  }

  private function getMinimumStability(InputInterface $input) {
    if ($input->hasOption('stability')) {
      return $input->getOption('stability') ?: 'stable';
    }

    $file = Factory::getComposerFile();
    if (is_file($file) && is_readable($file) && is_array($composer = json_decode(file_get_contents($file), true))) {
      if (!empty($composer['minimum-stability'])) {
        return $composer['minimum-stability'];
      }
    }

    return 'stable';
  }

  /**
   * Given a package name, this determines the best version to use in the require key.
   *
   * This returns a version with the ~ operator prefixed when possible.
   *
   * @param  InputInterface            $input
   * @param  string                    $name
   * @param  string|null               $phpVersion
   * @param  string                    $preferredStability
   * @param  string|null               $requiredVersion
   * @param  string                    $minimumStability
   * @throws \InvalidArgumentException
   * @return array                     name version
   */
  private function findBestVersionAndNameForPackage(InputInterface $input, $name, $phpVersion, $preferredStability = 'stable', $requiredVersion = null, $minimumStability = null) {
    // find the latest version allowed in this pool
    $versionSelector = new VersionSelector($this->getPool($input, $minimumStability));
    $ignorePlatformReqs = $input->hasOption('ignore-platform-reqs') && $input->getOption('ignore-platform-reqs');

    // ignore phpVersion if platform requirements are ignored
    if ($ignorePlatformReqs) {
      $phpVersion = null;
    }

    $package = $versionSelector->findBestCandidate($name, $requiredVersion, $phpVersion, $preferredStability);

    if (!$package) {
      // platform packages can not be found in the pool in versions other than the local platform's has
      // so if platform reqs are ignored we just take the user's word for it
      if ($ignorePlatformReqs && preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $name)) {
        return array($name, $requiredVersion ?: '*');
      }

      // Check whether the PHP version was the problem
      if ($phpVersion && $versionSelector->findBestCandidate($name, $requiredVersion, null, $preferredStability)) {
        throw new \InvalidArgumentException(sprintf(
            'Package %s at version %s has a PHP requirement incompatible with your PHP version (%s)',
            $name,
            $requiredVersion,
            $phpVersion
        ));
      }
      // Check whether the required version was the problem
      if ($requiredVersion && $versionSelector->findBestCandidate($name, null, $phpVersion, $preferredStability)) {
        throw new \InvalidArgumentException(sprintf(
            'Could not find package %s in a version matching %s',
            $name,
            $requiredVersion
        ));
      }
      // Check whether the PHP version was the problem
      if ($phpVersion && $versionSelector->findBestCandidate($name)) {
        throw new \InvalidArgumentException(sprintf(
            'Could not find package %s in any version matching your PHP version (%s)',
            $name,
            $phpVersion
        ));
      }

      // Check for similar names/typos
      $similar = $this->findSimilar($name);
      if ($similar) {
        // Check whether the minimum stability was the problem but the package exists
        if ($requiredVersion === null && in_array($name, $similar, true)) {
          throw new \InvalidArgumentException(sprintf(
              'Could not find a version of package %s matching your minimum-stability (%s). Require it with an explicit version constraint allowing its desired stability.',
              $name,
              $this->getMinimumStability($input)
          ));
        }

        throw new \InvalidArgumentException(sprintf(
            "Could not find package %s.\n\nDid you mean " . (count($similar) > 1 ? 'one of these' : 'this') . "?\n    %s",
            $name,
            implode("\n    ", $similar)
        ));
      }

      throw new \InvalidArgumentException(sprintf(
          'Could not find a matching version of package %s. Check the package spelling, your version constraint and that the package is available in a stability which matches your minimum-stability (%s).',
          $name,
          $this->getMinimumStability($input)
      ));
    }

    return array(
      $package->getPrettyName(),
      $versionSelector->findRecommendedRequireVersion($package),
    );
  }

  private function findSimilar($package) {
    try {
      $results = $this->repos->search($package);
    }
    catch (\Exception $e) {
      // ignore search errors
      return array();
    }
    $similarPackages = array();

    foreach ($results as $result) {
      $similarPackages[$result['name']] = levenshtein($package, $result['name']);
    }
    asort($similarPackages);

    return array_keys(array_slice($similarPackages, 0, 5));
  }

  private function installDependencies($output) {
    try {
      $installCommand = $this->getApplication()->find('install');
      $installCommand->run(new ArrayInput(array()), $output);
    }
    catch (\Exception $e) {
      $this->getIO()->writeError('Could not install dependencies. Run `composer install` to see more information.');
    }
  }

  private function hasDependencies($options) {
    $requires = (array) $options['require'];
    $devRequires = isset($options['require-dev']) ? (array) $options['require-dev'] : array();

    return !empty($requires) || !empty($devRequires);
  }

}
