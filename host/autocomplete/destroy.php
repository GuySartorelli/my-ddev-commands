#!/usr/bin/env php
<?php declare(strict_types=1);

use GuySartorelli\DdevPhpUtils\DDevHelper;

// Make sure autoload exists and include it
$commandsDir = $_SERVER['HOME'] . '/.ddev/commands';
$autoload = $commandsDir . '/.php-utils/vendor/autoload.php';
if (!file_exists($autoload)) {
    echo 'autoload file is missing - make sure you ran `composer install`.' . PHP_EOL;
    exit(1);
}
include_once $autoload;

// Get all known DDEV projects
$projects = DDevHelper::runJson('list');
// Remove projects already on the command line
$args = array_slice($argv, 2);
foreach ($projects as $i => $project) {
    if (in_array($project->name, $args)) {
        unset($projects[$i]);
    }
}
// Output, including the status
echo implode("\n", array_map(fn ($project) => "{$project->name}\t{$project->status}", $projects));
