<?php declare(strict_types=1);

namespace GuySartorelli\DdevPhpUtils;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

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
        $input = new ArgvInput();

        Output::init($input->hasParameterOption(['--verbose', '-v', '-vv', '-vvv'], true));

        set_exception_handler([self::class, 'handleException']);

        $input->bind($definition);
        $input->validate();

        return $input;
    }

    /**
     * Exception handler. Very reduced version of what symfony/console does in Application::renderThrowable().
     */
    public static function handleException(Throwable $e): void
    {
        if (Output::isVerbose()) {
            // Padding
            Output::getIO()->writeln('');
            $preface = OutputFormatter::escape(sprintf(
                'In %s line %s:',
                basename($e->getFile()) ?: 'n/a',
                $e->getLine() ?: 'n/a')
            );
            Output::getIO()->writeln(sprintf('<comment>%s</comment>', $preface));
        }

        Output::error(OutputFormatter::escape(trim($e->getMessage())));

        if (Output::isVerbose()) {
            Output::getIO()->writeln('<comment>Exception trace:</comment>');

            // exception related properties
            $trace = $e->getTrace();

            array_unshift($trace, [
                'function' => '',
                'file' => $e->getFile() ?: 'n/a',
                'line' => $e->getLine() ?: 'n/a',
                'args' => [],
            ]);

            for ($i = 0, $count = \count($trace); $i < $count; ++$i) {
                $class = $trace[$i]['class'] ?? '';
                $type = $trace[$i]['type'] ?? '';
                $function = $trace[$i]['function'] ?? '';
                $file = $trace[$i]['file'] ?? 'n/a';
                $line = $trace[$i]['line'] ?? 'n/a';

                Output::getIO()->writeln(sprintf(
                    ' %s%s at <info>%s:%s</info>',
                    $class,
                    $function ? $type.$function.'()' : '',
                    $file,
                    $line)
                );
                // Padding
                Output::getIO()->writeln('');
            }
        }
    }
}
