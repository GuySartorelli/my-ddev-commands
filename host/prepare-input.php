#!/usr/bin/env php
<?php declare(strict_types=1);

## Usage: prepare-input <input>
## Description: Convert markdown list of links into a format ready for use in other commands
## Flags: [{"Name":"pr","Type":"bool","Usage":"Whether to format the output to be used in the --pr flag of other commands","DefValue":"false"}, {"Name":"no-clipboard","Type":"bool","Usage":"Output instead of copying to clipboard","DefValue":"true"}]
## CanRunGlobally: true
## ExecRaw: false

use GuySartorelli\DdevPhpUtils\Output;
use GuySartorelli\DdevPhpUtils\Validation;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;

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
        'pr',
        null,
        InputOption::VALUE_NONE,
        'Whether to format the output to be used in the --pr flag of other commands'
    ),
    new InputOption(
        'clipboard',
        null,
        InputOption::VALUE_NEGATABLE,
        'Whether to copy to clipboard or not',
        true
    ),
]);
$input = Validation::validate($definition);

$lines = explode(PHP_EOL, trim($input->getArgument('input')));

foreach ($lines as &$line) {
    if (!str_starts_with($line, '- ')) {
        throw new RuntimeException('Expected URLs in markdown list. Line was ' . $line);
    }
    $line = ltrim($line, '- ');
}

function outputFormattedValue(string $value, InputInterface $input)
{
    if ($input->getOption('clipboard')) {
        $value = escapeshellarg($value);
        $x = Process::fromShellCommandline("echo $value | xclip -selection clipboard");
        $x->setTty(true);
        $x->mustRun();
    } else {
        Output::step($value);
    }
}

if ($input->getOption('pr')) {
    outputFormattedValue('--pr=' . implode(' --pr=', $lines), $input);
} else {
    outputFormattedValue(implode(' ', $lines), $input);
}
