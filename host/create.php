#!/usr/bin/env php
<?php declare(strict_types=1);

## Usage: create [options] <project-name>
## Description: Creates a new opinionated Silverstripe CMS DDEV project inside a pre-defined directory.
## Flags: [{"Name":"verbose","Shorthand":"v","Type":"bool","Usage":"verbose output"},{"Name":"recipe","Shorthand":"r","Type":"string","Usage":"The recipe to install. Options: core, cms, installer, sink, or any recipe composer name (e.g. 'silverstripe/recipe-cms')","DefValue":"installer"},{"Name":"constraint","Shorthand":"c","Type":"string","Usage":"The version constraint to use for the installed recipe.","DefValue":"5.x-dev"},{"Name":"extra-module","Shorthand":"m","Type":"string","Usage":"Any additional modules to be required before dev/build. Can be used multiple times."},{"Name":"composer-option","Shorthand":"o","Type":"string","Usage":"Any additional arguments to be passed to the composer create-project command."},{"Name":"php-version","Shorthand":"P","Type":"string","Usage":"The PHP version to use for this environment. Uses the lowest allowed version by default."},{"Name":"db","Type":"string","Usage":"The database Type to be used. Must be one of 'mariadb', 'mysql'.","DefValue":"mysql"},{"Name":"db-version","Type":"string","Usage":"The version of the database docker image to be used."},{"Name":"pr","Type":"string","Usage":"Optional pull request URL or github referece, e.g. 'silverstripe/silverstripe-framework#123' or 'https://github.com/silverstripe/silverstripe-framework/pull/123'. If included, the command will checkout out the PR branch in the appropriate vendor package. Can be used multiple times."},{"Name":"pr-has-deps","Type":"bool","Usage":"A PR from the --pr option has dependencies which need to be included in the first composer install.","DefValue":"false"},{"Name":"include-recipe-testing","Type":"bool","Usage":"Include silverstripe/recipe-testing even if it isnt in the chosen recipe.","DefValue":"true"},{"Name":"include-frameworktest","Type":"bool","Usage":"Include silverstripe/frameworktest even if it isn't in the chosen recipe.","DefValue":"true"}]
## CanRunGlobally: true
## ExecRaw: false

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Repository;
use GuySartorelli\DdevPhpUtils\ComposerJsonService;
use GuySartorelli\DdevPhpUtils\DDevHelper;
use GuySartorelli\DdevPhpUtils\GitHubService;
use GuySartorelli\DdevPhpUtils\Output;
use GuySartorelli\DdevPhpUtils\ProjectCreatorHelper;
use GuySartorelli\DdevPhpUtils\Validation;
use Packagist\Api\Client as PackagistClient;
use Packagist\Api\PackageNotFoundException;
use Packagist\Api\Result\Package\Version;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

// Because of silliness, this could be in either the project's .ddev/.global_commands/host/ or $HOME/.ddev/commands/host/
$commandsDir = $_SERVER['HOME'] . '/.ddev/commands';

// Make sure autoload exists and include it
$autoload = $commandsDir . '/.php-utils/vendor/autoload.php';
if (!file_exists($autoload)) {
    echo 'autoload file is missing - make sure you ran `composer install`.' . PHP_EOL;
    exit(1);
}
include_once $autoload;

/**
 * @var string[]
 * Used to define short names to easily select common recipes
 */
$recipeShortcuts = [
    'installer' => 'silverstripe/installer',
    'sink' => 'silverstripe/recipe-kitchen-sink',
    'core' => 'silverstripe/recipe-core',
    'cms' => 'silverstripe/recipe-cms',
    // we need an admin recipe
];

$filesystem = new Filesystem();
/**
 * @var string
 * Root directory for the project
 */
$projectRoot = null;
/** @var ?Version */
$recipeVersionDetails = null;
$prs = [];

$recipeDescription = '';
foreach ($recipeShortcuts as $shortcut => $recipe) {
    $recipeDescription .= "\"$shortcut\" ($recipe), ";
}
// DON'T FORGET TO UPDATE THE FLAGS ANNOTATION IN THE META COMMENT!
$definition = new InputDefinition([
    new InputArgument(
        'project-name',
        InputArgument::OPTIONAL,
        'The name of the project. This will be used for the directory and the webhost.'
        . ' Defaults to a name generated based on the recipe and constraint.'
        . ' Must not contain the following characters: ' . DDevHelper::INVALID_PROJECT_NAME_CHARS
    ),
    new InputOption(
        'recipe',
        'r',
        InputOption::VALUE_REQUIRED,
        'The recipe to install. Options: ' . $recipeDescription . 'any recipe composer name (e.g. "silverstripe/recipe-cms")',
        'installer'
    ),
    new InputOption(
        'constraint',
        'c',
        InputOption::VALUE_REQUIRED,
        'The version constraint to use for the installed recipe.',
        '5.x-dev'
    ),
    new InputOption(
        'extra-module',
        'm',
        InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
        'Any additional modules to be required before dev/build.',
        []
    ),
    new InputOption(
        'composer-option',
        'o',
        InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
        'Any additional arguments to be passed to the composer create-project command.',
        ['--prefer-source']
    ),
    new InputOption(
        'php-version',
        'P',
        InputOption::VALUE_REQUIRED,
        'The PHP version to use for this environment. Uses the lowest allowed version by default.'
    ),
    new InputOption(
        'db',
        null,
        InputOption::VALUE_REQUIRED,
        // @TODO eventually might want postgres and sqlite3 again
        'The database type to be used. Must be one of "mariadb", "mysql".',
        'mysql'
    ),
    new InputOption(
        'db-version',
        null,
        InputOption::VALUE_REQUIRED,
        'The version of the database docker image to be used.'
    ),
    new InputOption(
        'pr',
        null,
        InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
        <<<DESC
        Optional pull request URL or github referece, e.g. "silverstripe/silverstripe-framework#123" or "https://github.com/silverstripe/silverstripe-framework/pull/123"
        If included, the command will checkout out the PR branch in the appropriate vendor package.
        Multiple PRs can be included (for separate modules) by using `--pr` multiple times.
        DESC,
        []
    ),
    new InputOption(
        'pr-has-deps',
        null,
        InputOption::VALUE_NONE,
        'A PR from the --pr option has dependencies which need to be included in the first composer install.'
    ),
    new InputOption(
        'include-recipe-testing',
        null,
        InputOption::VALUE_NEGATABLE,
        'Include silverstripe/recipe-testing even if it isnt in the chosen recipe.',
        true
    ),
    new InputOption(
        'include-frameworktest',
        null,
        InputOption::VALUE_NEGATABLE,
        'Include silverstripe/frameworktest even if it isnt in the chosen recipe.',
        true
    ),
]);
$input = Validation::validate($definition);

// VALIDATION

/**
 * Normalises the recipe to be installed based on $recipeShortcuts
 */
function normaliseRecipe(): void
{
    global $input, $recipeShortcuts;
    // Normalise recipe based on shortcuts
    $recipe = $input->getOption('recipe');
    if (isset($recipeShortcuts[$recipe])) {
        $recipe = $recipeShortcuts[$recipe];
        $input->setOption('recipe', $recipe);
    }

    // Validate recipe exists
    $recipeDetailsSet = [];
    try {
        $packagist = new PackagistClient();
        $recipeDetailsSet = $packagist->getComposer($recipe);
    } catch (PackageNotFoundException) {
        // no-op, it'll be thrown in our exception below.
    }
    if (!array_key_exists($recipe, $recipeDetailsSet)) {
        throw new InvalidOptionException("The recipe '$recipe' doesn't exist in packagist");
    }
    $recipeDetails = $recipeDetailsSet[$recipe];

    // Validate recipe has a version matching the constraint
    $versionDetailsSet = $recipeDetails->getVersions();
    $constraint = $input->getOption('constraint');
    $versionDetails = $versionDetailsSet[$constraint] ?? null;
    if (!$versionDetails) {
        $versionCandidates = Semver::satisfiedBy(array_keys($versionDetailsSet), $constraint);
        if (empty($versionCandidates)) {
            throw new InvalidOptionException("The recipe '$recipe' has no versions compatible with the constraint '$constraint");
        }
        $versionDetails = $versionDetailsSet[Semver::rsort($versionCandidates)[0]];
    }
    global $recipeVersionDetails;
    $recipeVersionDetails = $versionDetails;
}

/**
 * Finds and sets the lowest compatible PHP version for the recipe,
 * if no version was passed via CLI.
 */
function identifyPhpVersion(): void
{
    global $input, $recipeVersionDetails;
    $phpVersion = $input->getOption('php-version');
    // @TODO validate php version?
    if ($phpVersion) {
        return;
    }

    $dependencies = $recipeVersionDetails->getRequire();
    if (!isset($dependencies['php'])) {
        // @TODO get from dependencies of dependencies if we ever hit this
        throw new InvalidOptionException('Unable to detect appropriate PHP version, as the chosen recipe has no direct constraint for PHP');
    }

    // Get the lowest possible PHP version allowed by the constraint
    // Note we can't go for the heighest since there's no guarantee such a version exists
    $versionParser = new VersionParser();
    $phpConstraint = $versionParser->parseConstraints($dependencies['php']);
    $phpVersion = $phpConstraint->getLowerBound()->getVersion();
    $input->setOption('php-version', substr($phpVersion, 0, 3));
}

/**
 * Gets the default name for this project based on the options provided.
 */
function getDefaultProjName(): string
{
    global $input;
    $invalidCharsRegex = '/[' . preg_quote(DDevHelper::INVALID_PROJECT_NAME_CHARS, '/') . ']/';
    // Normalise recipe by replacing 'invalid' chars with hyphen
    $recipeParts = explode('-', preg_replace($invalidCharsRegex, '-', $input->getOption('recipe')));
    $recipe = end($recipeParts);
    // Normalise constraints to remove stability flags
    $constraint = preg_replace('/^(dev-|v(?=\d))|-dev|(#|@).*?$/', '', $input->getOption('constraint'));
    $constraint = preg_replace($invalidCharsRegex, '-', trim($constraint, '~^'));
    $name = $recipe . '--' . $constraint;

    if (!empty($input->getOption('pr'))) {
        $name .= '--' . 'with-prs';
    }

    return $name;
}

normaliseRecipe();
identifyPhpVersion();
$projectName = ProjectCreatorHelper::getProjectName($input->getArgument('project-name') ?? '', getDefaultProjName(), true);
$projectRoot = Path::join(DDevHelper::getCustomConfig('projects_path'), $projectName);
ProjectCreatorHelper::validateOptions($input, $projectRoot, true);

if (in_array('--no-install', $input->getOption('composer-option')) && !empty($input->getOption('pr'))) {
    Output::warning('Composer --no-install has been set. Cannot checkout PRs.');
} elseif (!empty($input->getOption('pr'))) {
    $prs = GitHubService::getPullRequestDetails($input->getOption('pr'));
}

// EXECUTION

function createProjectRoot(): bool
{
    global $filesystem, $projectRoot;
    Output::step('Creating project directory');

    try {
        // Make the project directory
        if (!is_dir($projectRoot)) {
            $filesystem->mkdir($projectRoot);
        }
    } catch (IOExceptionInterface $e) {
        // @TODO replace this with more standardised error/failure handling.
        Output::error("Couldn't create project directory: {$e->getMessage()}");
        Output::debug($e->getTraceAsString());

        return false;
    }

    return true;
}

/**
 * Runs composer require for the given module if it should be included.
 */
function includeOptionalModule(string $moduleName, bool $shouldInclude = true, bool $isDev = false)
{
    global $input;
    if ($shouldInclude) {
        Output::subStep("Adding optional module $moduleName");
        $args = [
            'require',
            $moduleName,
            ...ProjectCreatorHelper::prepareComposerArgs($input, 'require'),
        ];

        if ($isDev) {
            $args[] = '--dev';
        }

        // Run composer command
        $success = DDevHelper::runInteractiveOnVerbose('composer', $args);
        if (!$success) {
            Output::warning("Couldn't require <options=bold>$moduleName</> - add that dependency manually.");
        }
        return $success;
    }
}

/**
 * Creates composer project and adds extra dependencies
 */
function setupComposerProject(): bool
{
    global $input;
    Output::step('Creating composer project');

    // Run composer command
    $args = ProjectCreatorHelper::prepareComposerCommand($input, 'create');
    $success = DDevHelper::runInteractiveOnVerbose('composer', $args);
    if (!$success) {
        Output::error('Couldn\'t create composer project.');
        return false;
    }
    Output::endProgressBar();

    // Add allowed plugins, in case the recipe you're using doesn't have them in its composer config
    DDevHelper::run('composer', ['config', 'allow-plugins.composer/installers', 'true']);
    DDevHelper::run('composer', ['config', 'allow-plugins.silverstripe/recipe-plugin', 'true']);
    DDevHelper::run('composer', ['config', 'allow-plugins.silverstripe/vendor-plugin', 'true']);
    // required for phpstan
    DDevHelper::run('composer', ['config', 'allow-plugins.phpstan/extension-installer', 'true']);
    // required for linting docs
    DDevHelper::run('composer', ['config', 'allow-plugins.dealerdirect/phpcodesniffer-composer-installer', 'true']);

    // Install optional modules as appropriate
    Output::step('Adding additional composer dependencies');
    includeOptionalModule('behat/mink-selenium2-driver', isDev: true);
    includeOptionalModule('friends-of-behat/mink-extension', isDev: true); // for CMS 4
    includeOptionalModule('silverstripe/frameworktest', (bool) $input->getOption('include-frameworktest'), isDev: true);
    includeOptionalModule('silverstripe/recipe-testing', (bool) $input->getOption('include-recipe-testing'), isDev: true);
    // for linting
    includeOptionalModule('php-parallel-lint/php-parallel-lint', isDev: true);
    includeOptionalModule('php-parallel-lint/php-console-highlighter', isDev: true);
    includeOptionalModule('silverstripe/standards', isDev: true);
    includeOptionalModule('phpstan/extension-installer', isDev: true);
    // for linting docs
    includeOptionalModule('silverstripe/documentation-lint', isDev: true);
    // Always include dev docs if we're not using sink, which has it as a dependency
    includeOptionalModule('silverstripe/developer-docs', ($input->getOption('recipe') !== 'silverstripe/recipe-kitchen-sink'));

    foreach ($input->getOption('extra-module') as $module) {
        includeOptionalModule($module);
    }

    Output::endProgressBar();
    return $success;
}

/**
 * Adds PRs as forks so they can be installed by composer.
 * This is only called if PRs have dependency changes.
 */
function handlePRsWithDeps(): bool
{
    global $projectRoot, $prs;
    // Add prs to composer.json
    Output::step('Adding PRs to composer.json so we can pull in their dependencies');
    $composerService = new ComposerJsonService($projectRoot);
    $composerService->addForks($prs);
    $composerService->addForkedDeps($prs);

    Output::endProgressBar();
    return true;
}

/**
 * Outputs a warning and marks the checkout process as failed
 */
function failCheckout(string $composerName, mixed &$success): void
{
    Output::warning('Could not check out PR for <options=bold>' . $composerName . '</> - please check out that PR manually.');
    $success = false;
}

/**
 * Add remotes for the PRs and swap to the relevant branches
 */
function checkoutPRs(): bool
{
    global $filesystem, $projectRoot, $prs;
    Output::step('Checking out PRs');
    $success = true;
    foreach ($prs as $composerName => $details) {
        Output::subStep('Setting up PR for ' . $composerName);
        Output::subStep('Setting remote ' . $details['remote'] . ' as "' . $details['remoteName'] . '" and checking out branch ' . $details['prBranch']);

        // Try to add dependency if it's not there already
        $prPath = Path::join($projectRoot, 'vendor', $composerName);
        if (!$filesystem->exists($prPath)) {
            // Try composer require-ing it - and if that fails, toss out a warning about it and move on.
            Output::subStep($composerName . ' is not yet added as a dependency - requiring it.');
            $checkoutSuccess = DDevHelper::runInteractiveOnVerbose('composer', ['require', $composerName, '--prefer-source']);
            if (!$checkoutSuccess) {
                failCheckout($composerName, $success);
                continue;
            }
        }

        try {
            $gitRepo = new Repository($prPath);
            $gitRepo->run('remote', ['add', $details['remoteName'], $details['remote']]);
            $gitRepo->run('fetch', [$details['remoteName']]);
            $gitRepo->run('switch', ["{$details['remoteName']}/" . $details['prBranch'], '--track', '--no-guess']);
        } catch (ProcessException) {
            failCheckout($composerName, $success);
            continue;
        }
    }
    Output::endProgressBar();
    return $success;
}

// Set up project root directory
$success = createProjectRoot();
if (!$success) {
    // @TODO rollback
    exit(1);
}

chdir($projectRoot);

// DDEV config
$success = ProjectCreatorHelper::setupDdevProject($input, $projectName);
if (!$success) {
    // @TODO rollback
    exit(1);
}

$success = setupComposerProject();
if (!$success) {
    // @TODO rollback?
    exit(1);
}

if (!empty($prs) && $input->getOption('pr-has-deps')) {
    // If PRs have dependencies, handle that before composer install.
    handlePRsWithDeps();
    // The lock file doesn't know about those deps, so composer install would have failed
    // and for some reason composer update doesn't work in its place, so we must remove
    // the lock file before installing.
    $filesystem->remove('composer.lock');
}

// Run composer install
if (in_array('--no-install', $input->getOption('composer-option'))) {
    Output::warning('Opted not to run `composer install` - dont forget to run that!');
} else {
    Output::step('Running composer install now that dependencies have been added');
    $args = ProjectCreatorHelper::prepareComposerCommand($input, 'install');
    $success = DDevHelper::runInteractiveOnVerbose('composer', $args);
    if (!$success) {
        Output::error('Couldn\'t run composer install.');
        // @TODO rollback?
        exit(1);
    }
}

// Don't fail if we didn't get PRs in - we can add those manually if needs be.
if (!empty($prs) && !$input->getOption('pr-has-deps')) {
    // If PRs don't have deps, handle it _after_ composer install so we can checkout the right branch
    checkoutPRs();
}

$success = ProjectCreatorHelper::copyProjectFiles($commandsDir, $projectRoot, true);
if (!$success) {
    // @TODO rollback?
    exit(1);
}

// Build database
if (in_array('--no-install', $input->getOption('composer-option'))) {
    Output::warning('--no-install passed to composer-option, cannot build database.');
} else {
    Output::step('Building database');
    $success = DDevHelper::runInteractiveOnVerbose('exec', ['sake', 'dev/build']);
    if (!$success) {
        Output::warning("Couldn't build database - run <options=bold>ddev exec sake dev/build</>");
    }
    Output::endProgressBar();
}

$details = DDevHelper::getProjectDetails();
Output::success("Created environment <options=bold>{$details->name}</>. Go to <options=bold>{$details->primary_url}/admin</>");

if (!$success) {
    exit(1);
};
