#!/usr/bin/env php
<?php declare(strict_types=1);

## Usage: attach [project-dir]
## Description: Attaches an opinionated Silverstripe CMS DDEV project to a pre-existing directory.
## Flags: [{"Name":"verbose","Shorthand":"v","Type":"bool","Usage":"verbose output"},{"Name":"composer-option","Shorthand":"o","Type":"string","Usage":"Any additional arguments to be passed to the composer create-project command."},{"Name":"php-version","Shorthand":"P","Type":"string","Usage":"The PHP version to use for this environment. Uses .platform.yml by default if available, otherwise uses the lowest allowed version by default."},{"Name":"db","Type":"string","Usage":"The database Type to be used. Must be one of 'mariadb', 'mysql'.","DefValue":"mysql"},{"Name":"db-version","Type":"string","Usage":"The version of the database docker image to be used."}]
## CanRunGlobally: true
## ExecRaw: false

use Composer\Semver\VersionParser;
use GuySartorelli\DdevPhpUtils\ComposerJsonService;
use GuySartorelli\DdevPhpUtils\DDevHelper;
use GuySartorelli\DdevPhpUtils\GitHubService;
use GuySartorelli\DdevPhpUtils\Output;
use GuySartorelli\DdevPhpUtils\ProjectCreatorHelper;
use GuySartorelli\DdevPhpUtils\Validation;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Yaml;

// Because of silliness, this could be in either the project's .ddev/.global_commands/host/ or $HOME/.ddev/commands/host/
$commandsDir = $_SERVER['HOME'] . '/.ddev/commands';

// Make sure autoload exists and include it
$autoload = $commandsDir . '/.php-utils/vendor/autoload.php';
if (!file_exists($autoload)) {
    echo 'autoload file is missing - make sure you ran `composer install`.' . PHP_EOL;
    exit(1);
}
include_once $autoload;

// DON'T FORGET TO UPDATE THE FLAGS ANNOTATION IN THE META COMMENT!
$definition = new InputDefinition([
    new InputArgument(
        'project-dir',
        InputArgument::OPTIONAL,
        'The directory to attach to. Defaults to the current directory.',
        './'
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
        'The PHP version to use for this environment. Uses .platform.yml by default if available, otherwise uses the lowest allowed version by default.'
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
]);
$input = Validation::validate($definition);

$projectDir = $input->getArgument('project-dir');
if (Path::isRelative($projectDir)) {
    $projectDir = Path::makeAbsolute($projectDir, getcwd());
}
$projectDir = Path::canonicalize($projectDir);
$success = chdir($projectDir);
if (!$success) {
    throw new RuntimeException("Couldn't swap to $projectDir");
}

// VALIDATION

$composerJsonService = new ComposerJsonService('./');

function getPhpVersionFromPlatform(): ?string
{
    if (!file_exists('.platform.yml')) {
        Output::debug('No .platform.yml file to check');
        return null;
    }

    $parsed = Yaml::parseFile('.platform.yml', Yaml::PARSE_OBJECT_FOR_MAP);

    if (!isset($parsed->php_settings->version) || !is_numeric($parsed->php_settings->version)) {
        Output::warning('Invalid or missing PHP version in .platform.yml file - checking composer.json instead.');
        return null;
    }

    return (string) $parsed->php_settings->version;
}

function getPhpVersionFromComposer(): ?string
{
    global $composerJsonService;
    $phpConstraint = $composerJsonService->getCurrentComposerConstraint('php');
    $versionParser = new VersionParser();

    if (!$phpConstraint) {
        $checkDependencies = [
            'silverstripe/recipe-cms',
            'silverstripe/recipe-core',
            'silverstripe/framework',
            'silverstripe/cms',
            'silverstripe/admin',
        ];
        foreach ($checkDependencies as $dependency) {
            $constraint = $composerJsonService->getCurrentComposerConstraint($dependency);
            if (!$constraint) {
                continue;
            }

            $constraint = $versionParser->parseConstraints($constraint);
            $minorBranch = substr($constraint->getLowerBound()->getVersion(), 0, 3);
            $composerJson = GitHubService::getComposerJsonForIdentifier($dependency, $minorBranch);

            $phpConstraint = $composerJson->require->php ?? '';
            if ($phpConstraint) {
                break;
            }
        }
    }

    if (!$phpConstraint) {
        return null;
    }

    // Get the lowest possible PHP version allowed by the constraint
    // Note we can't go for the heighest since there's no guarantee such a version exists or is supported
    $phpConstraint = $versionParser->parseConstraints($phpConstraint);
    $phpVersion = $phpConstraint->getLowerBound()->getVersion();
    return substr($phpVersion, 0, 3);
}

/**
 * Gets the version of PHP to use
 *
 * Looks for the PHP version in the following order:
 * 1. The version explicitly passed in via CLI.
 * 2. The PHP version defined in .platform.yml if that's available.
 * 3. The lowest compatible PHP version for the project if defined.
 * 4. The lowest compatible PHP version in various dependencies.
 */
function getPhpVersion(): string
{
    global $input;
    $phpVersion = $input->getOption('php-version');
    // @TODO validate php version?
    if ($phpVersion) {
        Output::subStep('Using explicit PHP version from flags');
        return $phpVersion;
    }

    $phpVersion = getPhpVersionFromPlatform();

    // @TODO validate php version?
    if ($phpVersion) {
        return $phpVersion;
    }

    $phpVersion = getPhpVersionFromComposer();

    // @TODO validate php version?
    if ($phpVersion) {
        return $phpVersion;
    }

    throw new RuntimeException('Couldnt guess PHP version for this project. Either use `--php-version` or add a PHP constraint to composer.json');
}

if (DDevHelper::isInProject()) {
    throw new RuntimeException('Cannot attach to an existing DDEV project. Run `ddev start`.');
}

ProjectCreatorHelper::validateOptions($input, null);
$composerJsonService->validateComposerJsonExists();
$phpVersion = getPhpVersion();
$projectName = basename($projectDir);
$suggestedName = preg_replace('#[' . preg_quote(DDevHelper::INVALID_PROJECT_NAME_CHARS, '#') . ']#', '-', $projectName);
if (!ProjectCreatorHelper::validateProjName($suggestedName, true)) {
    $suggestedName = null;
}
$projectName = ProjectCreatorHelper::getProjectName($projectName, $suggestedName);

// EXECUTION

/**
 * Spins up an opinionated DDEV project, adds extra extensions, etc
 */
function setupDdevProject(): bool
{
    global $input, $phpVersion, $projectName;
    Output::step('Spinning up DDEV project');

    $dbType = $input->getOption('db');
    $dbVersion = $input->getOption('db-version');
    if ($dbVersion) {
        $db = "--database={$dbType}:{$dbVersion}";
    } else {
        $db = "--db-image={$dbType}";
    }

    $success = DDevHelper::runInteractiveOnVerbose(
        'config',
        [
            $db,
            '--webserver-type=apache-fpm',
            '--webimage-extra-packages=php${DDEV_PHP_VERSION}-tidy',
            '--project-type=php',
            '--php-version=' . $phpVersion,
            '--project-name=' . $projectName,
            '--timezone=Pacific/Auckland',
            '--docroot=public',
            '--create-docroot',
        ]
    );
    if (!$success) {
        Output::error('Failed to set up DDEV project.');
        return false;
    }

    $hasBehat = DDevHelper::runInteractiveOnVerbose('add-on', ['get', 'ddev/ddev-selenium-standalone-chrome']);
    if (!$hasBehat) {
        Output::warning('Could not add DDEV addon <options=bold>ddev/ddev-selenium-standalone-chrome</> - add that manually.');
    }

    $hasDbAdmin = DDevHelper::runInteractiveOnVerbose('add-on', ['get', 'ddev/ddev-adminer']);
    if (!$hasDbAdmin) {
        Output::warning('Could not add DDEV addon <options=bold>ddev/ddev-adminer</> - add that manually.');
    }

    DDevHelper::runInteractiveOnVerbose('start', []);

    Output::endProgressBar();
    return true;
}

// DDEV config
$success = ProjectCreatorHelper::setupDdevProject($input, $projectName);
if (!$success) {
    // @TODO rollback
    exit(1);
}

ProjectCreatorHelper::shareComposerToken();

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

// If .env already exists, update it to be ddev compliant
if (file_exists('.env')) {
    $envContent = file_get_contents('.env');
    $copyEnvContent = file_get_contents(Path::join($commandsDir, '.php-utils', 'copy-to-project', '.env'));
    preg_match_all('/^[a-zA-Z].*$/m', $copyEnvContent, $matches);

    // Comment out existing config
    foreach ($matches[0] as $line) {
        $parts = explode('=', $line, 2);
        if (count($parts) < 2) {
            continue;
        }
        $envContent = preg_replace('/^' . preg_quote($parts[0], '/') . '/m', '#' . $parts[0], $envContent);
    }

    // Add in DDEV config
    $envContent .= "\n\n\n#### DDEV settings\n\n" . $copyEnvContent;
    $success = file_put_contents('.env', $envContent);
    if (!$success) {
        Output::error('Failed to write .env content');
        // @TODO rollback?
        exit(1);
    }
}

$success = ProjectCreatorHelper::copyProjectFiles($commandsDir, './', $projectName, false);
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
