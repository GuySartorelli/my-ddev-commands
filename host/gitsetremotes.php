#!/usr/bin/env php
<?php declare(strict_types=1);

// Based on https://gist.github.com/maxime-rainville/0e2cc280cc9d2e014a21b55a192076d9

## Description: Set the various development remotes in the git project for the current working dir. Also adds a pre-push hook to modules in DDEV projects.
## Usage: remotes
## Example: "ddev remotes [options]"
## Flags: [{"Name":"verbose","Shorthand":"v","Type":"bool","Usage":"verbose output"},{"Name":"rename-origin","Shorthand":"r","DefValue":"true","Usage":"Rename the 'origin' remote to 'orig'"},{"Name":"security","Shorthand":"s","Usage":"Add the security remote instead of the creative commoners remote"},{"Name":"fetch","Shorthand":"f","Usage":"Run git fetch after defining remotes"},{"Name":"no-hooks","Usage":"Skip adding pre-push hook"}]
## CanRunGlobally: true
## ExecRaw: false

use Gitonomy\Git\Repository;
use GuySartorelli\DdevPhpUtils\DDevHelper;
use GuySartorelli\DdevPhpUtils\Output;
use GuySartorelli\DdevPhpUtils\Validation;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
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
    new InputOption(
        'no-hooks',
        null,
        InputOption::VALUE_NONE,
        'Skip adding pre-push hook'
    ),
]);
$input = Validation::validate($definition);

$gitRepo = new Repository(Path::canonicalize('./'));
$ccAccount = 'git@github.com:creative-commoners/';
$securityAccount = 'git@github.com:silverstripe-security/';
$prefixAndOrgRegex = '#^(?>git@github\.com:|https://github\.com/).*/#';

$remotes = explode("\n", trim($gitRepo->run('remote', ['show'])));
$origin = in_array('origin', $remotes) ? 'origin' : 'orig';

$originUrl = trim($gitRepo->run('remote', ['get-url', $origin]));

// Validate origin URL
if (!preg_match($prefixAndOrgRegex, $originUrl)) {
    throw new LogicException("Origin $originUrl does not appear to be valid");
}

// Add remotes
if ($input->getOption('security')) {
    if (in_array('security', $remotes)) {
        Output::step('security remote already exists');
    } else {
        // Add security remote
        Output::step('Adding the security remote');
        $securityRemote = preg_replace($prefixAndOrgRegex, $securityAccount, $originUrl);
        $gitRepo->run('remote', ['add', 'security', $securityRemote]);
    }
} else {
    if (in_array('cc', $remotes)) {
        Output::step('cc remote already exists');
    } else {
        // Add cc remote
        Output::step('Adding the creative-commoners remote');
        $ccRemote = preg_replace($prefixAndOrgRegex, $ccAccount, $originUrl);
        $gitRepo->run('remote', ['add', 'cc', $ccRemote]);
    }
}

// Rename origin
if ($input->getOption('rename-origin')) {
    if (in_array('orig', $remotes)) {
        Output::step('origin remote already renamed');
    } else {
        Output::step('Renaming the origin remote');
        $gitRepo->run('remote', ['rename', 'origin', 'orig']);
    }
}

// Fetch
if ($input->getOption('fetch')) {
    Output::step('Fetching all remotes');
    $gitRepo->run('fetch', ['--all']);
}

// Pre-push hook
$cwd = getcwd() ?: '';
$isModule = preg_match('#.*?/vendor/([a-z_.-]+/[a-z_.-]+)#', $cwd, $matches);
if (!$input->getOption('no-hooks') && $isModule && DDevHelper::isInProject()) {
    $moduleName = $matches[1];
    $moduleDir = $matches[0];
    $hookPath = Path::join($moduleDir, '.git/hooks/pre-push');
    if (file_exists($hookPath)) {
        Output::step('Pre-push hook already exists');
    } else {
        Output::step('Creating pre-push hook');
        $doclintRcPath = Path::join($moduleDir, '.doclintrc');
        $canLintPhp = ($moduleName === 'silverstripe/developer-docs' || $moduleName === 'silverstripe/frameworktest') ? 0 : 1;
        $hookCode = '';
        // Include doc linting
        $hook = <<<HOOK
        #!/bin/bash

        # Ensures PHP code and docs are linted before pushing changes.
        # Called by "git push" after it has checked the remote status, but before anything has been pushed.
        # If this script exits with a non-zero status nothing will be pushed.
        #
        # This hook is called with the following parameters:
        #
        # $1 -- Name of the remote to which the push is being done
        # $2 -- URL to which the push is being done

        # clear stdin so phpcs doesn't try to use it. Sigh.
        read -t 0.1 -n 10000 discard

        # Lint documentation
        if [ -f $doclintRcPath ]; then
            echo "Running ddev lint-docs"
            ddev lint-docs $moduleName
            if [ $? -ne 0 ]; then
                echo "DOC LINTING FAILED - FIX LINTING ISSUES BEFORE PUSHING"
                exit 1
            fi
            # make sure there's a blank line between docs and PHP linting so it's easier to see what's what
            echo ""
        fi

        # Lint PHP
        if [ $canLintPhp -eq 1 ]; then
            echo "Running ddev lint"
            ddev lint $moduleName
            if [ $? -ne 0 ]; then
                echo "PHP LINTING FAILED - FIX LINTING ISSUES BEFORE PUSHING"
                exit 1
            fi
        fi

        echo "PASSED LINTING"
        exit 0
        HOOK;
        file_put_contents($hookPath, $hook);
        chmod($hookPath, 0755);
    }
}

$successMsg = 'Remotes added';
if ($input->getOption('fetch')) {
    $successMsg .= ' and fetched';
}
Output::success($successMsg);
