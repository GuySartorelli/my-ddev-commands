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
use Symfony\Component\Console\Question\ChoiceQuestion;
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
// Technically it shouldn't ever be included in the JSON output of `ddev list` anyway,
// but in case that ever changes, explicitly don't allow destroying the Router.
if (in_array('Router', $projectNames)) {
    throw new RuntimeException('The Router must not be destroyed');
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
$numSkipped = 0;
foreach ($projectNames as $projectName) {
    $successSwap = chdir($projectRoot[$projectName]);

    // Check if there were changes in the project before destroying it, so I don't delete work in progress.
    $changes = DDevHelper::run('changes');
    if ($changes !== 'no changes') {
        $question = new ChoiceQuestion("<options=bold>$projectName</> has changes. Do you want to continue?", ['y', 'n', '?']);
        $question->setErrorMessage('Choose [y]es, [n]o, or [?] to see which modules have changes.');
        $result = '?';
        while ($result === '?') {
            $result = Output::getIO()->askQuestion($question);
            if ($result === '?') {
                Output::getIO()->writeln($changes);
            }
        }
        if ($result !== 'y') {
            Output::step("Skipping project <options=bold>$projectName</> - result was '$result'");
            $numSkipped++;
            continue;
        }
    }

    Output::step("Destroying project <options=bold>$projectName</>");

    if (!$successSwap) {
        Output::error('Could not swap to DDEV project.');
        continue;
    }

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

$numFailed = count($projectNames) - ($numSkipped + $numSucceeded);
if ($numFailed !== 0) {
    Output::error("Failed to destroy <options=bold>{$numFailed}</> projects");
    exit(1);
} else {
    Output::success("Destroyed <options=bold>{$numSucceeded}</> projects successfully");
}
