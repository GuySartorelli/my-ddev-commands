#!/usr/bin/env php
<?php declare(strict_types=1);

## Usage: prepare-input <input>
## Description: Convert markdown list of links into a format ready for use in other commands
## Flags: [{"Name":"format","Shorthand":"f","Type":"string","Usage":"The format to output. One of 'pr' or 'spaces'","DefValue":"spaces","AutocompleteTerms":["pr","spaces"]}]
## CanRunGlobally: true
## ExecRaw: false

use GuySartorelli\DdevPhpUtils\Output;
use GuySartorelli\DdevPhpUtils\Validation;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

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
        'input',
        InputArgument::REQUIRED,
        'PR links in markdown list format',
    ),
    new InputOption(
        'format',
        'f',
        InputOption::VALUE_REQUIRED,
        'The format to output. One of "pr" or "spaces"',
        'spaces'
    ),
]);
$input = Validation::validate($definition);

$lines = explode(PHP_EOL, trim($input->getArgument('input')));
$format = $input->getOption('format');

foreach ($lines as &$line) {
    if (!str_starts_with($line, '- ')) {
        throw new RuntimeException('Expected URLs in markdown list. Line was ' . $line);
    }
    $line = ltrim($line, '- ');
}

switch (strtolower($format)) {
    case 'pr':
        Output::step('--pr=' . implode(' --pr=', $lines));
        break;
    case 'spaces':
        Output::step(implode(' ', $lines));
        break;
    default:
        throw new RuntimeException('Format not accepted: ' . $format);
}
