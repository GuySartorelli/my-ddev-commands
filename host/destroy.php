#!/usr/bin/env php
<?php declare(strict_types=1);

## Usage: destroy <project>
## Description: Fully destroy a project, removing all trace of its existence from the face of the... well, the computer.
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
        'project-name',
        InputArgument::REQUIRED,
        'The name of the project to destroy - use ddev list if you aren\'t sure.',
    ),
]);
$input = Validation::validate($definition);

// Validation
$projectName = $input->getArgument('project-name');
if (!$projectName) {
    return;
}

$allProjects = DDevHelper::runJson('list');
if (empty($allProjects)) {
    throw new RuntimeException('There are no current DDEV projects to destroy');
}

$found = false;
foreach ($allProjects as $projectDetails) {
    if ($projectDetails->name !== $projectName) {
        continue;
    }
    $found = true;
    $projectRoot = $projectDetails->approot;
    break;
}

if (!$found) {
    throw new RuntimeException("Project $projectName doesn't exist");
}


// Execution
Output::step("Destroying project $projectName");

chdir($projectRoot);

Output::step("Shutting down DDEV project");
$success = DDevHelper::runInteractiveOnVerbose('delete', ['-O', '-y']);
if (!$success) {
    Output::error('Could not shut down DDEV project.');
    return self::FAILURE;
}

Output::step("Deleting project directory");
$filesystem = new Filesystem();
try {
    $filesystem->remove($projectRoot);
} catch (IOExceptionInterface $e) {
    Output::error('Could not delete project directory: ' . $e->getMessage());
    Output::debug($e->getTraceAsString());
    return self::FAILURE;
}

Output::success("Project <options=bold>{$projectName}</> successfully destroyed");
