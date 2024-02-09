<?php

namespace GuySartorelli\DdevPhpUtils;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class Output
{
    private const STYLE_END = '</>';

    private SymfonyStyle $output;
    
    public function __construct()
    {
        $this->output = new SymfonyStyle(new ArrayInput([]), new ConsoleOutput());
    }

    /**
     * Nice standardised output style for outputting step information
     */
    public function step(string $output): void
    {
        // $this->clearProgressBar();
        $this->output->writeln('<fg=blue>' . $output . self::STYLE_END);
    }

    /**
     * Outputs the message with nice "success" styling
     */
    public function success(string $message): void
    {
        // Retain background style inside any formatted sections
        $message = preg_replace('#(<)([^/]+>.+?</>)#', '$1bg=bright-green;$2', $message);
        // Render the message
        $this->output->block($message, 'OK', 'fg=black;bg=bright-green', padding: true, escape: false);
    }
}