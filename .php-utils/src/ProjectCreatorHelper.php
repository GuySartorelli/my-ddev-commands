<?php declare(strict_types=1);

namespace GuySartorelli\DdevPhpUtils;

use RecursiveDirectoryIterator;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Helper class to avoid duplication of code between the `create` and `attach` commands
 */
final class ProjectCreatorHelper
{
    /**
     * Validate input options for the command, so far as this class cares about them.
     * @throws \Exception if any option is invalid.
     */
    public static function validateOptions(InputInterface $input, ?string $projectRoot, bool $isCreate = false): void
    {
        if ($projectRoot !== null) {
            // Validate project path
            $rootNotEmpty = is_dir($projectRoot) && (new RecursiveDirectoryIterator($projectRoot, RecursiveDirectoryIterator::SKIP_DOTS))->valid();
            if ($rootNotEmpty) {
                throw new RuntimeException('Project root path must be empty.');
            }
            if (is_file($projectRoot)) {
                throw new RuntimeException('Project root path must not be a file.');
            }
        }

        // Validate DB
        $validDbDrivers = [
            'mysql',
            'mariadb',
            // @TODO add postgres and sqlite3 support again?
        ];
        if (!in_array($input->getOption('db'), $validDbDrivers)) {
            throw new InvalidOptionException('--db must be one of ' . implode(', ', $validDbDrivers));
        }

        if ($isCreate) {
            // Validate recipe
            // see https://getcomposer.org/doc/04-schema.md#name for regex
            if (!preg_match('%^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$%', $input->getOption('recipe'))) {
                throw new InvalidOptionException('recipe must be a valid composer package name.');
            }

            // @TODO validate if extra module(s) even exist
        }

        // @TODO validate if composer options are valid??
    }

    /**
     * Validates a project name against a set of banned characters, and ensures it's unique.
     */
    public static function validateProjName(string $name, bool $suppressWarning = false): bool
    {
        // Name must have a value
        if (!$name) {
            return false;
        }
        // Name must not represent a pre-existing project
        if (DDevHelper::getProjectDetails($name) !== null) {
            if (!$suppressWarning) {
                Output::warning('A project with that name already exists');
            }
            return false;
        }
        // Name must not have invalid characters
        $invalidCharsRegex = '/[' . preg_quote(DDevHelper::INVALID_PROJECT_NAME_CHARS, '/') . ']/';
        return !preg_match($invalidCharsRegex, $name);
    }

    /**
     * Get the project name, asking for one if the current one is invalid.
     */
    public static function getProjectName(string $currentName, ?string $suggestedName, bool $isCreate = false): string
    {
        if (!ProjectCreatorHelper::validateProjName($currentName, !$isCreate)) {
            Output::warning('The current directory name is not a valid DDEV project name.');
            $currentName = Output::getIO()->ask('Name this project.', $suggestedName, function (string $answer): string {
                if (!ProjectCreatorHelper::validateProjName($answer)) {
                    throw new RuntimeException(
                        'You must provide an project name. It must be unique and not contain the following characters: '
                        . DDevHelper::INVALID_PROJECT_NAME_CHARS
                    );
                }
                return $answer;
            });
        }

        return $currentName;
    }

    /**
     * Spins up an opinionated DDEV project, adds extra extensions, etc
     */
    public static function setupDdevProject(InputInterface $input, string $projectName): bool
    {
        Output::step('Spinning up DDEV project');

        $dbType = $input->getOption('db');
        $dbVersion = $input->getOption('db-version');
        if ($dbVersion) {
            $db = "--database={$dbType}:{$dbVersion}";
        } else {
            $db = "--db-image={$dbType}";
        }

        $success = DDevHelper::runInteractiveOnVerbose(
            'config',
            [
                $db,
                '--webserver-type=apache-fpm',
                '--webimage-extra-packages=php${DDEV_PHP_VERSION}-tidy',
                '--project-type=php',
                '--php-version=' . $input->getOption('php-version'),
                '--project-name=' . $projectName,
                '--timezone=Pacific/Auckland',
                '--docroot=public',
                '--create-docroot',
            ]
        );
        if (!$success) {
            Output::error('Failed to set up DDEV project.');
            return false;
        }

        $hasBehat = DDevHelper::runInteractiveOnVerbose('get', ['ddev/ddev-selenium-standalone-chrome']);
        if (!$hasBehat) {
            Output::warning('Could not add DDEV addon <options=bold>ddev/ddev-selenium-standalone-chrome</> - add that manually.');
        }

        $hasDbAdmin = DDevHelper::runInteractiveOnVerbose('get', ['ddev/ddev-adminer']);
        if (!$hasDbAdmin) {
            Output::warning('Could not add DDEV addon <options=bold>ddev/ddev-adminer</> - add that manually.');
        }

        DDevHelper::runInteractiveOnVerbose('start', []);

        Output::endProgressBar();
        return true;
    }

    /**
     * Prepares the arguments for a given composer command, taking
     * the passed composer options into account.
     */
    public static function prepareComposerArgs(InputInterface $input, string $commandType): array
    {
        // Prepare composer command
        $args = [
            '--no-interaction',
            ...$input->getOption('composer-option')
        ];

        // `composer install` can't take --no-audit, but we don't want to include audits in other commands.
        // We also don't want to install until the install step.
        if ($commandType !== 'install') {
            $args[] = '--no-install';
            $args[] = '--no-audit';
        }

        // Make sure --no-install isn't in there twice.
        return array_unique($args);
    }

    /**
     * Gets a composer command ready to be called.
     */
    public static function prepareComposerCommand(InputInterface $input, string $commandType)
    {
        $args = self::prepareComposerArgs($input, $commandType);
        $command = [
            $commandType,
            ...$args
        ];
        if ($commandType === 'create') {
            $command[] = '--no-scripts';
            $command[] = $input->getOption('recipe') . ':' . $input->getOption('constraint');
        }
        return $command;
    }

    /**
     * Copy files to the project. Has to be done AFTER composer create
     */
    public static function copyProjectFiles(string $commandsDir, string $copyTo, bool $replaceExisting = false): bool
    {
        Output::step('Copying opinionated files to project');
        try {
            // Copy files through (config, .env, etc)
            $filesystem = new Filesystem();
            $filesystem->mirror(
                Path::join($commandsDir, '.php-utils', 'copy-to-project'),
                $copyTo,
                options: ['override' => $replaceExisting]
            );
        } catch (IOExceptionInterface $e) {
            // @TODO replace this with more standardised error/failure handling.
            Output::error("Couldn't copy project files: {$e->getMessage()}");
            Output::debug($e->getTraceAsString());
            return false;
        }
        return true;
    }
}
