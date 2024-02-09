#!/usr/bin/env php
<?php

// Based on https://gist.github.com/maxime-rainville/0e2cc280cc9d2e014a21b55a192076d9

## Description: Set the various development remotes in the git project for the current working dir.
## Usage: remotes
## Example: "ddev remotes [options]"
## Flags: [{"Name":"rename-origin","Shorthand":"r","DefValue":"true","Usage":"Rename the 'origin' remote to 'orig'"},{"Name":"security","Shorthand":"s","Usage":"Add the security remote instead of the creative commoners remote"},{"Name":"fetch","Shorthand":"f","Usage":"Run git fetch after defining remotes"}]
## CanRunGlobally: true
## ExecRaw: false

use Gitonomy\Git\Repository;
use GuySartorelli\DdevPhpUtils\Output;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Path;

// Because of siliness, this could be in either the project's .ddev/.global_commands/host/ or $HOME/.ddev/commands/host/
$commandsDir = $_SERVER['HOME'] . '/.ddev/commands';

// Make sure autoload exists and include it
$autoload = $commandsDir . '/.php-utils/vendor/autoload.php';
if (!file_exists($autoload)) {
    throw new RuntimeException('autoload file is missing - make sure you ran `composer install`.');
}
include_once $autoload;

$output = new Output();

$definition = new InputDefinition([
    new InputOption(
        'rename-origin',
        'r',
        InputOption::VALUE_NEGATABLE,
        'Rename the "origin" remote to "orig"',
        true
    ),
    new InputOption(
        'security',
        's',
        InputOption::VALUE_NONE,
        'Add the security remote instead of the creative commoners remote'
    ),
    new InputOption(
        'fetch',
        'f',
        InputOption::VALUE_NONE,
        'Run git fetch after defining remotes'
    ),
]);
$input = new ArgvInput(definition: $definition);
$input->validate();

$gitRepo = new Repository(Path::canonicalize('./'));
$ccAccount = 'git@github.com:creative-commoners/';
$securityAccount = 'git@github.com:silverstripe-security/';
$prefixAndOrgRegex = '#^(?>git@github\.com:|https://github\.com/).*/#';

$originUrl = trim($gitRepo->run('remote', ['get-url', 'origin']));

// Validate origin URL
if (!preg_match($prefixAndOrgRegex, $originUrl)) {
    throw new LogicException("Origin $originUrl does not appear to be valid");
}

// Add remotes
if ($input->getOption('security')) {
    // Add security remote
    $output->step('Adding the security remote');
    $securityRemote = preg_replace($prefixAndOrgRegex, $securityAccount, $originUrl);
    $gitRepo->run('remote', ['add', 'security', $securityRemote]);
} else {
    // Add cc remote
    $output->step('Adding the creative-commoners remote');
    $ccRemote = preg_replace($prefixAndOrgRegex, $ccAccount, $originUrl);
    $gitRepo->run('remote', ['add', 'cc', $ccRemote]);
}

// Rename origin
if ($input->getOption('rename-origin')) {
    $output->step('Renaming the origin remote');
    $gitRepo->run('remote', ['rename', 'origin', 'orig']);
}

// Fetch
if ($input->getOption('fetch')) {
    $output->step('Fetching all remotes');
    $gitRepo->run('fetch', ['--all']);
}

$successMsg = 'Remotes added';
if ($input->getOption('fetch')) {
    $successMsg .= ' and fetched';
}

$output->success($successMsg);