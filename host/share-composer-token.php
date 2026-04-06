#!/usr/bin/env php
<?php declare(strict_types=1);

## Usage: share-token
## Description: Share the host's composer token with the web container
## ExecRaw: false

use GuySartorelli\DdevPhpUtils\Output;
use GuySartorelli\DdevPhpUtils\ProjectCreatorHelper;
use Symfony\Component\Console\Output\OutputInterface;

// Because of silliness, this could be in either the project's .ddev/.global_commands/host/ or $HOME/.ddev/commands/host/
$commandsDir = $_SERVER['HOME'] . '/.ddev/commands';

// Make sure autoload exists and include it
$autoload = $commandsDir . '/.php-utils/vendor/autoload.php';
if (!file_exists($autoload)) {
    echo 'autoload file is missing - make sure you ran `composer install`.' . PHP_EOL;
    exit(1);
}
include_once $autoload;

Output::init(OutputInterface::VERBOSITY_NORMAL);

ProjectCreatorHelper::shareComposerToken();
