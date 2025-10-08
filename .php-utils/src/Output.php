<?php

namespace GuySartorelli\DdevPhpUtils;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;

final class Output
{
    private const STYLE_END = '</>';

    private static SymfonyStyle $output;

    private static ?ProgressBar $progressBar = null;

    private static bool $progressBarDisplayed = false;

    public static function init(bool $verbose): void
    {
        $verbosity = $verbose ? ConsoleOutput::VERBOSITY_DEBUG : ConsoleOutput::VERBOSITY_NORMAL;
        self::$output = new SymfonyStyle(new ArrayInput([]), new ConsoleOutput(verbosity: $verbosity));
    }

    public static function getIO(): SymfonyStyle
    {
        return self::$output;
    }

    public static function isVerbose(): bool
    {
        return self::$output->isVerbose();
    }

    public static function isVeryVerbose(): bool
    {
        return self::$output->isVeryVerbose();
    }

    /**
     * Plain output of a line of text when in debug mode.
     * If we're not in debug mode and there's a progress bar, outputs the message there instead.
     */
    public static function debug(string $message): void
    {
        if (!self::$output->isDebug() && self::$progressBar !== null) {
            self::advanceProgressBar($message);
        }
        self::$output->writeln($message, ConsoleOutput::VERBOSITY_DEBUG);
    }

    /**
     * Nice standardised output style for outputting step information.
     * Also clears any progress bars in progress.
     */
    public static function step(string $message): void
    {
        self::clearProgressBar();
        self::$output->writeln('<fg=blue>' . $message . self::STYLE_END);
    }


    /**
     * Nice standardised output style for outputting sub-step information.
     * Also clears any progress bars in progress.
     */
    public static function subStep(string $output): void
    {
        self::clearProgressBar();
        self::$output->writeln('<fg=gray>' . $output . self::STYLE_END);
    }

    /**
     * Outputs the message with nice "success" styling.
     * Also clears any progress bars in progress.
     */
    public static function success(string $message): void
    {
        self::endProgressBar();
        // Retain background style inside any formatted sections
        $message = preg_replace('#(<)([^/]+>.+?</>)#', '$1bg=bright-green;$2', $message);
        // Render the message
        self::$output->block($message, 'OK', 'fg=black;bg=bright-green', padding: true, escape: false);
    }

    /**
     * Outputs the message with nice "warning" styling.
     * Also clears any progress bars in progress.
     */
    public static function warning(string|array $message)
    {
        self::clearProgressBar();
        // Retain colour style inside any formatted sections
        $message = preg_replace('#(<)([^/]+>.+?</>)#', '$1bg=yellow;$2', $message);
        // Render the message
        self::$output->block($message, 'WARNING', 'fg=black;bg=yellow', padding: true, escape: false);
    }

    /**
     * Outputs the message with nice "error" styling.
     * Also stops any progress bars in progress.
     */
    public static function error(string|array $message): void
    {
        self::endProgressBar();
        // Retain colour style inside any formatted sections
        $message = preg_replace('#(<)([^/]+>.+?</>)#', '$1fg=white;bg=red;$2', $message);
        // Render the message
        self::$output->block($message, 'ERROR', 'fg=white;bg=red', padding: true, escape: false);
    }

    /**
     * Callable to pass into Process::run() to advance a progress bar when a long process
     * has some output
     */
    public static function handleProcessOutput($type, $data): void
    {
        self::advanceProgressBar($data);
    }

    /**
     * Advances the current progress bar, starting a new one if necessary.
     */
    public static function advanceProgressBar(?string $message = null): void
    {
        $barWidth = 15;
        $timeWidth = 20;
        if (self::$progressBar === null) {
            self::$progressBar = self::$output->createProgressBar();
            self::$progressBar->setFormat("%elapsed:10s% %bar% %message%");
            self::$progressBar->setBarWidth($barWidth);
            self::$progressBar->setMessage('');
        }
        if (!self::$progressBarDisplayed) {
            self::$progressBar->display();
            self::$progressBarDisplayed = true;
        }

        if ($message !== null) {
            // Make sure messages can't span multiple lines - truncate if necessary
            $terminal = new Terminal();
            $threshold = $terminal->getWidth() - $barWidth - $timeWidth - 5;
            $message = trim(Helper::removeDecoration(self::$output->getFormatter(), str_replace("\n", ' ', $message)));
            if (strlen($message) > $threshold) {
                $message = substr($message, 0, $threshold - 3) . '...';
            }
            self::$progressBar->setMessage($message);
        }

        self::$progressBar->advance();
    }

    /**
     * Clears the current progress bar (if any) from the console.
     *
     * Useful if we need to output a warning while a progress bar may be running.
     */
    public static function clearProgressBar(): void
    {
        if (self::$progressBarDisplayed) {
            self::$progressBar?->clear();
            self::$progressBarDisplayed = false;
        }
    }

    /**
     * Clears and unsets the progressbar if there is one.
     */
    public static function endProgressBar(): void
    {
        if (self::$progressBar !== null) {
            self::$progressBar->finish();
            self::$progressBar->clear();
            self::$progressBar = null;
            self::$progressBarDisplayed = false;
        }
    }
}
