#!/usr/bin/env php
<?php declare(strict_types=1);

use GuySartorelli\DdevPhpUtils\DDevHelper;

// After we enter the project name, don't suggest a second one.
if (count($argv) !== 3) {
    return;
}

// Make sure autoload exists and include it
$commandsDir = $_SERVER['HOME'] . '/.ddev/commands';
$autoload = $commandsDir . '/.php-utils/vendor/autoload.php';
if (!file_exists($autoload)) {
    echo 'autoload file is missing - make sure you ran `composer install`.' . PHP_EOL;
    exit(1);
}
include_once $autoload;

// Return all known DDEV projects
echo implode("\n", array_map(fn ($item) => "{$item->name}\t{$item->status}", DDevHelper::runJson('list')));
