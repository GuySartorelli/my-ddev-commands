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
            if (!$isCreate) {
                Output::warning('The current directory name is not a valid DDEV project name.');
            } else {
                Output::warning(
                    'You must provide an project name. It must be unique and not contain the following characters: '
                    . DDevHelper::INVALID_PROJECT_NAME_CHARS
                );
            }
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
    public static function setupDdevProject(InputInterface $input, string $projectName, $commandsDir, $copyTo): bool
    {
        Output::step('Spinning up DDEV project');

        $dbType = $input->getOption('db');
        $dbVersion = $input->getOption('db-version');
        if (!$dbVersion) {
            $dbVersion = static::getDbVersion($dbType);
        }
        Output::debug("Using database {$dbType}:{$dbVersion}");
        $db = "--database={$dbType}:{$dbVersion}";

        Output::subStep('Running ddev config');
        $success = DDevHelper::runInteractiveOnVerbose(
            'config',
            [
                $db,
                '--webserver-type=apache-fpm',
                '--webimage-extra-packages=php${DDEV_PHP_VERSION}-tidy',
                '--project-type=silverstripe',
                '--php-version=' . $input->getOption('php-version'),
                '--project-name=' . $projectName,
                '--timezone=Pacific/Auckland',
                '--docroot=public',
                '--create-docroot',
                '--disable-settings-management',
            ]
        );
        if (!$success) {
            Output::error('Failed to set up DDEV project.');
            return false;
        }

        Output::subStep('Adding adminer add-on');
        $hasDbAdmin = DDevHelper::runInteractiveOnVerbose('add-on', ['get', 'ddev/ddev-adminer']);
        if (!$hasDbAdmin) {
            Output::warning('Could not add DDEV addon <options=bold>ddev/ddev-adminer</> - add that manually.');
        }

        // Copy .ddev files to project
        Output::subStep('Copying custom .ddev/ files to project');
        try {
            $filesystem = new Filesystem();
            $filesystem->mirror(
                Path::join($commandsDir, '.php-utils', 'copy-to-project', '.ddev'),
                Path::join($copyTo, '.ddev'),
                options: ['override' => true]
            );
        } catch (IOExceptionInterface $e) {
            // @TODO replace this with more standardised error/failure handling.
            Output::error("Couldn't copy .ddev/ files: {$e->getMessage()}");
            Output::debug($e->getTraceAsString());
            return false;
        }

        Output::subStep('Starting DDEV project containers');
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

        // `composer install` can't take --prefer-lowest so remove that if it's included.
        if ($commandType === 'install') {
            $index = array_search('--prefer-lowest', $args);
            if ($index !== false) {
                unset($args[$index]);
            }
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
        if ($commandType === 'create-project') {
            $command[] = '--no-scripts';
            $command[] = $input->getOption('recipe') . ':' . $input->getOption('constraint');
        }
        return $command;
    }

    /**
     * Copy files to the project. Has to be done AFTER `composer create-project`
     */
    public static function copyProjectFiles(string $commandsDir, string $copyTo, string $projectName, bool $replaceExisting = false): bool
    {
        Output::step('Copying opinionated files to project');
        try {
            // Copy files through (config, .env, etc)
            $filesystem = new Filesystem();
            // @TODO eventually I should pass in an iterator to the mirror() method to not re-copy .ddev/ stuff
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

        return static::createAppNameConfig($copyTo, $projectName);
    }

    private static function createAppNameConfig(string $copyTo, string $projectName): bool
    {
        Output::debug('Creating config file to adjust application name');
        $filesystem = new Filesystem();
        $configDir = Path::join($copyTo, '.ddev-extra', '_config');

        if (!is_dir($configDir)) {
            $configDir = Path::join($copyTo, 'app', '_config');
            if (!is_dir($configDir)) {
                Output::error('Couldn\'t identify config directory');
                return false;
            }
        }

        $content = <<<EOL
        ---
        Name: ddev-extra-appname
        ---
        SilverStripe\Admin\LeftAndMain:
          application_name: '$projectName'

        SilverStripe\SiteConfig\SiteConfig:
          extensions:
            appname: DevTools\Extension\SiteConfigExtension
        EOL;

        try {
            $filesystem->dumpFile(Path::join($configDir, 'appname.yml'), $content);
        } catch (IOExceptionInterface $e) {
            // @TODO replace this with more standardised error/failure handling.
            Output::error("Couldn't create appname.yml: {$e->getMessage()}");
            Output::debug($e->getTraceAsString());
            return false;
        }
        return true;
    }

    /**
     * Share a GitHub token with Composer - used when installing forks.
     * Note this is only shared for as long as the container is up.
     * As soon as the container is stopped (e.g. with ddev stop) the token is no longer shared.
     */
    public static function shareComposerToken(): void
    {
        $token = DDevHelper::getCustomConfig('composer_token');
        if ($token) {
            Output::subStep('Sharing token with Composer');
            DDevHelper::runInteractiveOnVerbose('composer', ['config', '-g', 'github-oauth.github.com', $token]);
        } else {
            Output::warning('No composer token to share - check composer_token in .php-utils/config.yml');
        }
    }

    public static function setPreferredInstall(): void
    {
        Output::subStep('Setting preferred install type for supported organisations');
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.silverstripe/*', 'source']);
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.silverstripe-themes/*', 'source']);
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.creative-commoners/*', 'source']);
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.symbiote/*', 'source']);
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.tractorcow/*', 'source']);
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.silverstripe/*', 'source']);
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.dnadesign/*', 'source']);
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.bringyourownideas/*', 'source']);
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.colymba/*', 'source']);
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.cwp/*', 'source']);
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.guysartorelli/*', 'source']);
        DDevHelper::runInteractiveOnVerbose('composer', ['config', 'preferred-install.*/*', 'dist']);
    }

    public static function devBuild(): void
    {
        Output::step('Building database');
        $cmsMajor = static::getCmsMajor();
        if ($cmsMajor === -1) {
            Output::warning('CMS Major could not be determined! Falling back to CMS 5 mode');
        }
        $buildCommand = $cmsMajor > 5 ? 'db:build' : 'dev/build';
        $success = DDevHelper::runInteractiveOnVerbose('exec', ['sake', $buildCommand]);
        if (!$success) {
            Output::warning("Couldn't build database - run <options=bold>ddev exec sake {$buildCommand}</>");
        }
        Output::endProgressBar();

    }

    private static function getCmsMajor(): int
    {
        // @TODO consider using `composer show silverstripe/framework --format=json` instead
        $result = DDevHelper::run('composer', ['show', 'silverstripe/framework', '--format=json']);
        $json = json_decode($result, true);
        if (!$json || !array_key_exists('versions', $json)) {
            // @TODO consider error handling
            return -1;
        }
        foreach ($json['versions'] as $version) {
            // e.g. 6.1.0, 6.1.x-dev, 6.x-dev
            if (!preg_match('/^[0-9]+\./', $version)) {
                continue;
            }
            $versionParts = explode('.', $version);
            return (int) $versionParts[0];
        }
        // @TODO consider error handling
        return -1;
    }

    private static function getDbVersion(string $type): string
    {
        $result = DDevHelper::runJson('config', ['--database=invalid'], false);
        if (!isset($result->msg) || !preg_match_all('/' . preg_quote($type) . ':([0-9]+(?:\.[0-9]+)?)/i', $result->msg, $matches)) {
            throw new RuntimeException("Cannot get DB version for $type - check if it's valid and if so, add the type yourself.");
        }
        return max($matches[1]);
    }
}
