#!/usr/bin/env php
<?php declare(strict_types=1);

## Usage: destroy <project>
## Description: Fully destroy a project or multiple projects, removing all trace of its existence from the face of the... well, the computer.
## Flags: [{"Name":"verbose","Shorthand":"v","Type":"bool","Usage":"verbose output"}]
## CanRunGlobally: true
## ExecRaw: false

use GuySartorelli\DdevPhpUtils\DDevHelper;
use GuySartorelli\DdevPhpUtils\Output;
use GuySartorelli\DdevPhpUtils\Validation;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

// Because of silliness, this could be in either the project's .ddev/.global_commands/host/ or $HOME/.ddev/commands/host/
$commandsDir = $_SERVER['HOME'] . '/.ddev/commands';

// Make sure autoload exists and include it
$autoload = $commandsDir . '/.php-utils/vendor/autoload.php';
if (!file_exists($autoload)) {
    echo 'autoload file is missing - make sure you ran `composer install`.' . PHP_EOL;
    exit(1);
}
include_once $autoload;

$definition = new InputDefinition([
    new InputArgument(
        'project-names',
        InputArgument::IS_ARRAY | InputArgument::REQUIRED,
        'The name of the project(s) to destroy - use ddev list if you aren\'t sure.',
    ),
]);
$input = Validation::validate($definition);

// Validation
$projectNames = $input->getArgument('project-names');
if (empty($projectNames)) {
    return;
}

$allProjects = DDevHelper::runJson('list');
if (empty($allProjects)) {
    throw new RuntimeException('There are no current DDEV projects to destroy');
}

$projectRoot = [];
foreach ($allProjects as $projectDetails) {
    if (!in_array($projectDetails->name, $projectNames)) {
        continue;
    }
    $projectRoot[$projectDetails->name] = $projectDetails->approot;
}
$missing = array_diff($projectNames, array_keys($projectRoot));
if (count($missing) !== 0) {
    throw new RuntimeException('These projects don\'t exist: ' . implode(', ', $missing));
}

// Execution
$numSucceeded = 0;
foreach ($projectNames as $projectName) {
    Output::step("Destroying project <options=bold>$projectName</>");

    chdir($projectRoot[$projectName]);

    Output::subStep("Shutting down DDEV project");
    $success = DDevHelper::runInteractiveOnVerbose('delete', ['-O', '-y']);
    if (!$success) {
        Output::error('Could not shut down DDEV project.');
        continue;
    }

    Output::subStep("Deleting project directory");
    $filesystem = new Filesystem();
    try {
        $filesystem->remove($projectRoot[$projectName]);
    } catch (IOExceptionInterface $e) {
        Output::error('Could not delete project directory: ' . $e->getMessage());
        Output::debug($e->getTraceAsString());
        continue;
    }

    $numSucceeded++;
    Output::step("Project {$projectName} successfully destroyed");
}

$numFailed = count($projectNames) - $numSucceeded;
if ($numFailed !== 0) {
    Output::error("Failed to destroy <options=bold>{$numFailed}</> projects");
    exit(1);
} else {
    Output::success("Destroyed <options=bold>{$numSucceeded}</> projects successfully");
}
