#!/usr/bin/env php
<?php declare(strict_types=1);

## Usage: clone <git-repo-or-identifier> [...git-repos-or-identifiers]
## Description: Clone one or more git repos and optionally check out PRs based on the URLs into a predetermined directory.
## Flags: [{"Name":"verbose","Shorthand":"v","Type":"bool","Usage":"verbose output"}]
## CanRunGlobally: true
## ExecRaw: false

use Gitonomy\Git\Admin as Git;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Repository;
use GuySartorelli\DdevPhpUtils\DDevHelper;
use GuySartorelli\DdevPhpUtils\GitHubService;
use GuySartorelli\DdevPhpUtils\Output;
use GuySartorelli\DdevPhpUtils\Validation;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
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
    new InputArgument(
        'identifier',
        InputArgument::REQUIRED | InputArgument::IS_ARRAY,
        'URL or org/repo#123 reference to a GitHub repo - optionally for a specific pull request'
    ),
]);
$input = Validation::validate($definition);

$identifiers = $input->getArgument('identifier');

$success = true;
foreach ($identifiers as $identifier) {
    $repoDetails = GitHubService::getRepositoryDetails($identifier);

    $cloneDir = Path::canonicalize(DDevHelper::getCustomConfig('clone_dir'));
    if (!is_dir($cloneDir)) {
        Output::error("<options=bold>$cloneDir</> does not exist or is not a directory. Check your <options=bold>clone_dir</> config variable.");
        exit(1);
    }
    Output::step("Cloning {$repoDetails['outputName']} into {$cloneDir}");

    $repoPath = Path::join($cloneDir, preg_replace('/^silverstripe-/', '', $repoDetails['repo']));
    Git::cloneRepository($repoPath, $repoDetails['cloneUri']);

    if (isset($repoDetails['pr'])) {
        $details = $repoDetails['pr'];
        Output::subStep('Setting remote ' . $details['remote'] . ' as "' . $details['remoteName'] . '" and checking out branch ' . $details['prBranch']);

        try {
            $gitRepo = new Repository($repoPath);
            $gitRepo->run('remote', ['add', $details['remoteName'], $details['remote']]);
            $gitRepo->run('fetch', [$details['remoteName']]);
            $gitRepo->run('checkout', ["{$details['remoteName']}/" . $details['prBranch'], '--track', '--no-guess']);
        } catch (ProcessException $e) {
            Output::error("Could not check out PR branch <options=bold>{$details['prBranch']}</> - please check it out manually.");
            $success = false;
        }
    } elseif (isset($repoDetails['branch'])) {
        $branch = $repoDetails['branch'];
        Output::subStep('Checking out branch ' . $branch);
        try {
            $gitRepo = new Repository($repoPath);
            $gitRepo->run('switch', [$branch]);
        } catch (ProcessException $e) {
            Output::error("Could not check out branch <options=bold>{$branch}</> - please check it out manually.");
            $success = false;
        }
    }

    if ($success) {
        Output::step("{$repoDetails['outputName']} cloned successfully.");
    }
}

if ($success) {
    Output::success('Repo(s) cloned successfully.');
} else {
    exit(1);
}
