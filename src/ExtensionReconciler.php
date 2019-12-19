<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Drupal\Composer\Plugin\ComposerConverter\Extension\ExtensionRepository;

/**
 * Scans the file system, tells you which extensions are not accounted for.
 */
class ExtensionReconciler {

  /**
   * Array of 'exotic' extension human-readable names, keyed by machine name.
   *
   * @var string[]
   */
  protected $exoticSetupExtensions = NULL;

  /**
   * Extension package names, keyed by extension name.
   *
   * Values are package names, such as drupal/ajax_example, keys are the extension
   * name, such as ajax_example.
   *
   * @var string[]
   */
  protected $needThesePackages = NULL;

  /**
   * The composer.json file we'll inspect.
   *
   * @var \Drupal\Composer\Plugin\ComposerConverter\JsonFileUtility
   */
  protected $fromUtility;

  /**
   * The full path to the Composer working directory.
   *
   * This is assumed to contain any filesystem where we can find extensions.
   *
   * @var string
   */
  protected $workingDir;

  /**
   * All unreconciled extensions organized by project.
   *
   * Only extensions which have a project can be found here.
   *
   * @var string[][]
   */
  protected $projects = NULL;

  /**
   * Whether we should solve for Drupal dependencies based on their project name.
   *
   * @var bool
   */
  protected $preferProjects;

  /**
   *
   * @var \Drupal\Composer\Plugin\ComposerConverter\Extension\ExtensionRepository
   */
  protected $extensionRepository;

  /**
   * Construct a reconciler.
   *
   * @param \Drupal\Composer\Plugin\ComposerConverter\JsonFileUtility $from_utility
   *   Full path to a composer.json file we'll reconcile against.
   * @param string $working_dir
   *   Full path to the working directory as specified from Composer. This is
   *   assumed to contain any filesystem where we can find extensions.
   */
  public function __construct(JsonFileUtility $from_utility, $working_dir, $prefer_projects = FALSE) {
    $this->fromUtility = $from_utility;
    // Check whether the working dir obviously exists, because realpath() makes it
    // difficult to inject vfsStream for testing.
    if (!file_exists($working_dir)) {
      $working_dir = realpath($working_dir);
    }
    $this->workingDir = $working_dir;
    $this->preferProjects = $prefer_projects;
    $this->extensionRepository = ExtensionRepository::create($working_dir);
  }

  /**
   * Get packages for extensions in filesystem, but not in composer.json.
   *
   * @return string[]
   *   Array of extension package names, such as drupal/ajax_example, keyed by
   *   the extension name, such as ajax_example.
   */
  public function getUnreconciledPackages() {
    if ($this->needThesePackages === NULL) {
      $this->processPackages();
    }
    return $this->needThesePackages;
  }

  /**
   * Get all the extension objects that are unreconciled.
   *
   * @return \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension[]
   *   Array of Extension objects for each unreconciled extension keyed by machine
   *   name.
   */
  public function getAllUnreconciledExtensions() {
    $extension_objects = [];
    if ($this->projects === NULL) {
      $this->processPackages();
    }
    $extensions = $this->extensionRepository->getExtensions();
    foreach ($this->projects as $project_name => $extension_names) {
      foreach ($extension_names as $extension_name) {
        $extension_objects[$extension_name] = $extensions[$extension_name];
      }
    }
    return $extension_objects;
  }

  /**
   * Get extension packages specified in the composer.json we're converting.
   *
   * @param bool $dev
   *   (optional) Whether to look in require-dev instead of require. Defaults to
   *   FALSE.
   *
   * @return string[]
   *   Composer package specifications for extensions. Key is package name and value
   *   is version constraint.
   */
  public function getSpecifiedExtensions($dev = FALSE) {
    $require_spec = [];
    if ($this->projects === NULL) {
      $this->processPackages();
    }
    $require = $this->fromUtility->getRequire($dev);
    foreach ($this->projects as $project_name => $extensions) {
      // Did the user specify some extensions by their project name?
      $package = 'drupal/' . $project_name;
      if (array_key_exists($package, $require)) {
        $require_spec[$package] = $require[$package];
      }
      foreach ($extensions as $machine_name) {
        $package = 'drupal/' . $machine_name;
        if (array_key_exists($package, $require)) {
          $require_spec[$package] = $require[$package];
        }
      }
    }
    return $require_spec;
  }

  /**
   * Get packages for extensions in filesystem we don't know how to deal with.
   *
   * These could be path repos or other stuff we can't figure out.
   *
   * @return string[]
   *   Array of extension human-readable names we discovered, keyed by machine name.
   */
  public function getExoticPackages() {
    if ($this->exoticSetupExtensions === NULL) {
      $this->processPackages();
    }
    return $this->exoticSetupExtensions;
  }

  /**
   * Process our packages and filesystem into reconcilable information.
   */
  protected function processPackages() {
    // Find all the extensions in the file system, sorted by D.O project name.
    $this->projects = [];
    $this->exoticSetupExtensions = [];
    // This is our pre-parsed database of info about all the extensions. Keyed by
    // machine name.
    $extension_objects = [];

    foreach ($this->extensionRepository->getProjectNames() as $project_name) {
      $extensions = $this->extensionRepository->getExtensionsForProject($project_name);
      foreach ($extensions as $extension) {
        $machine_name = $extension->getMachineName();
        $this->projects[$project_name][$machine_name] = $machine_name;
      }
    }

    // Reconcile extensions against require and require-dev.
    $require = $this->fromUtility->getCombinedRequire();

    // Concern ourselves with drupal/ namespaced packages.
    $require = array_filter($require, function ($item) {
      return strpos($item, 'drupal/') === 0;
    }, ARRAY_FILTER_USE_KEY);

    // Make a list of extension/project names from our package names.
    $required_ext_or_proj_names = [];

    foreach (array_keys($require) as $package) {
      $boom_package = explode('/', $package);
      $extension_or_project_name = $boom_package[1];
      $required_ext_or_proj_names[$extension_or_project_name] = $extension_or_project_name;
      // If our extension is already required and belongs to a project, then
      // we should include that project in the list so that we account for other
      // extensions in the same project.
      // @todo This should be a query against the ExtensionCollection.
      if (isset($extension_objects[$extension_or_project_name])) {
        $p = $extension_objects[$extension_or_project_name]->getProject();
        $required_ext_or_proj_names[$p] = $p;
      }
    }

    // Handle exotic extensions which don't have a project name. These could
    // need a special repo or to not be required at all, so we just punt on
    // them.
    foreach ($this->extensionRepository->getExoticExtensions() as $machine_name => $extension) {
      $this->exoticSetupExtensions[$machine_name] = $extension->getName();
    }

    // D.O's Composer facade allows you to require an extension by extension
    // name or by project name, so we have to reconcile that.
    $this->needThesePackages = [];
    foreach ($this->projects as $project => $extensions) {
      // The user has already required the project in their composer.json by
      // project name. This also covers extensions where the extension name is
      // the same as the d.o project name.
      if (isset($required_ext_or_proj_names[$project])) {
        continue;
      }
      // Loop through all discovered extensions for this project to find out if
      // they're already in the composer.json.
      foreach ($extensions as $extension) {
        if (!in_array($extension, $required_ext_or_proj_names)) {
          if ($this->preferProjects) {
            $this->needThesePackages[$project] = $project;
          }
          else {
            $this->needThesePackages[$extension] = $extension;
          }
        }
      }
    }
    // Convert extension names to package names.
    foreach ($this->needThesePackages as $key => $value) {
      $this->needThesePackages[$key] = 'drupal/' . $value;
    }
  }

}
