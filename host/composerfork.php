#!/usr/bin/env php
<?php declare(strict_types=1);

## Usage: fork <fork-url> [...fork-urls]
## Description: Add a fork to composer.json. If the fork URL is for a PR, that PR branch is used for the dependency.
## Flags: [{"Name":"verbose","Shorthand":"v","Type":"bool","Usage":"verbose output"}]
## CanRunGlobally: true
## ExecRaw: false

use GuySartorelli\DdevPhpUtils\ComposerJsonService;
use GuySartorelli\DdevPhpUtils\GitHubService;
use GuySartorelli\DdevPhpUtils\Output;
use GuySartorelli\DdevPhpUtils\Validation;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;

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
        'fork-url',
        InputArgument::IS_ARRAY | InputArgument::REQUIRED,
        'URL for the repo (and optionally pull request) to fork. Multiple repos can be added.',
    ),
]);
$input = Validation::validate($definition);

$forks = $input->getArgument('fork-url');
if (empty($forks)) {
    throw new RuntimeException('At least one fork URL must be included.');
}

$composerService = new ComposerJsonService('./');
$composerService->validateComposerJsonExists();

Output::step('Getting fork details');
$forkDetails = GitHubService::getPullRequestDetails($forks, true);

Output::step('Adding fork(s)</>');
$composerService->addForks($forkDetails);

Output::step('Setting constraints for fork(s) if appropriate</>');
$composerService->addForkedDeps($forkDetails);

Output::success('Added fork(s) successfully.');
