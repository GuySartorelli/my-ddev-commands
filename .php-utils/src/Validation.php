<?php declare(strict_types=1);

namespace GuySartorelli\DdevPhpUtils;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class Validation
{
    /**
     * Validates system requirements and argv input
     */
    public static function validate(InputDefinition $definition): InputInterface
    {
        if (PHP_VERSION_ID < 80100) {
            echo 'This command requires at least PHP 8.1 and you are running ' . PHP_VERSION
             . ', please upgrade PHP. Aborting.' . PHP_EOL;
            exit(1);
        }

        $definition->addOption(new InputOption(
            'verbose',
            'v',
            InputOption::VALUE_NONE,
            'Provide verbose output including stack trace for errors'
        ));

        $input = new ArgvInput(definition: $definition);
        $input->validate();

        Output::init($input->getOption('verbose'));

        // TODO:
        // - Set up error handler to control error output
        // - Provide cleaner error output if not verbose

        return $input;
    }
}