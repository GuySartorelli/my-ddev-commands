<?php

namespace GuySartorelli\DdevPhpUtils;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class Output
{
    private const STYLE_END = '</>';

    private static SymfonyStyle $output;

    public static function init(bool $verbose): void
    {
        $verbosity = $verbose ? ConsoleOutput::VERBOSITY_VERY_VERBOSE : ConsoleOutput::VERBOSITY_NORMAL;
        self::$output = new SymfonyStyle(new ArrayInput([]), new ConsoleOutput(verbosity: $verbosity));
    }

    /**
     * Nice standardised output style for outputting step information
     */
    public static function step(string $output): void
    {
        // self::$clearProgressBar();
        self::$output->writeln('<fg=blue>' . $output . self::STYLE_END);
    }

    /**
     * Outputs the message with nice "success" styling
     */
    public static function success(string $message): void
    {
        // Retain background style inside any formatted sections
        $message = preg_replace('#(<)([^/]+>.+?</>)#', '$1bg=bright-green;$2', $message);
        // Render the message
        self::$output->block($message, 'OK', 'fg=black;bg=bright-green', padding: true, escape: false);
    }
}