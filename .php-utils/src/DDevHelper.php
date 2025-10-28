<?php declare(strict_types=1);

namespace GuySartorelli\DdevPhpUtils;

use RuntimeException;
use stdClass;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class DDevHelper
{
    /**
     * @var string
     * Characters that cannot be used for a project name
     */
    public const INVALID_PROJECT_NAME_CHARS = ' !@#$%^&*()"\',._<>/?:;\\';

    /**
     * Run a DDEV command interactively (assumes TTY is supported)
     *
     * @return bool Whether the command was successful or not
     */
    public static function runInteractive(string $command, array $args = []): bool
    {
        $process = new Process(['ddev', $command, ...$args]);
        Output::debug('Running command `' . $process->getCommandLine() . '`');
        $process->setTimeout(null);
        $process->setTty(true);
        $statusCode = $process->run();
        return $process->isSuccessful() && $statusCode === 0;
    }

    /**
     * Run a DDEV command interactively (assumes TTY is supported) when output is very verbose - but otherwise run it normally.
     * Takes an optional callback to handle output when not running interactively.
     *
     * @return bool Whether the command was successful or not
     */
    public static function runInteractiveOnVerbose(string $command, array $args = []): bool
    {
        if (Output::isVeryVerbose()) {
            return self::runInteractive($command, $args);
        }

        $process = new Process(['ddev', $command, ...$args]);
        $process->setTimeout(null);
        $statusCode = $process->run([Output::class, 'handleProcessOutput']);
        return $process->isSuccessful() && $statusCode === 0;
    }

    /**
     * Run a DDEV command non-interactively
     *
     * @return string The output from the command
     */
    public static function run(string $command, array $args = []): string
    {
        $process = new Process(['ddev', $command, ...$args]);
        $process->run();
        return $process->isSuccessful() ? $process->getOutput() : $process->getErrorOutput();
    }

    /**
     * Run a DDEV command and get the output as JSON
     *
     * @return mixed The output from the command as JSON output (e.g. stdClass), or null if there was no JSON output.
     */
    public static function runJson(string $command, array $args = [], bool $getRaw = true): mixed
    {
        $response = json_decode(self::run($command, [...$args, '--json-output']), false);
        if ($response === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('ERROR parsing JSON: ' . json_last_error_msg());
        }
        if ($getRaw) {
            return $response->raw ?? null;
        }
        return $response;
    }

    /**
     * Get the details of the project, if it exists.
     *
     * @param string $project The name of the project to get details for. If ommitted, the CWD is used.
     * @return stdClass|null The output from the command as a JSON object, or null if there was no JSON output.
     */
    public static function getProjectDetails(string $project = ''): ?stdClass
    {
        return self::runJson('describe', $project ? [$project] : []);
    }

    /**
     * Gets the named piece of custom config from config.yml (see README)
     */
    public static function getCustomConfig(string $config): mixed
    {
        $filePath = Path::join(__DIR__, '..', 'config.yml');
        if (!file_exists($filePath)) {
            throw new RuntimeException("File $filePath does not exist!");
        }
        $parsed = Yaml::parseFile($filePath, Yaml::PARSE_OBJECT_FOR_MAP);
        if (isset($parsed->$config)) {
            return $parsed->$config;
        }
        return null;
    }

    public static function isInProject(): bool
    {
        $details = self::getProjectDetails();
        return (bool) $details;
    }
}
