<?php

namespace GuySartorelli\DdevPhpUtils;

use Composer\Semver\VersionParser;
use stdClass;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class ComposerJsonService
{
    public const KEY_REQUIRE = 'require';
    public const KEY_REQUIRE_DEV = 'require-dev';

    private string $path;
    private Filesystem $fileSystem;

    public function __construct(string $basePath)
    {
        $this->fileSystem = new Filesystem();
        $this->path = Path::join($basePath, 'composer.json');
    }

    public function validateComposerJsonExists()
    {
        if (!$this->fileSystem->exists($this->path)) {
            throw new FileNotFoundException(path: $this->path);
        }
    }

    public function getComposerJson(bool $associative = true)
    {
        $this->validateComposerJsonExists();
        return json_decode(file_get_contents($this->path), $associative);
    }

    public function setComposerJson(stdClass|array $content)
    {
        $this->fileSystem->dumpFile(
            $this->path,
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function addForks(array $forkDetails)
    {
        $json = $this->getComposerJson();

        foreach ($forkDetails as $composerName => $fork) {
            $json['repositories'][$composerName] = [
                'type' => 'vcs',
                'url' => $fork['remote'],
            ];
        }

        $this->setComposerJson($json);
    }

    public function addForkedDeps(array $forkDetails)
    {
        $parser = new VersionParser();
        $json = $this->getComposerJson();

        foreach ($forkDetails as $composerName => $fork) {
            // Skip if we don't know what branch we should be targetting
            if (!isset($fork['prBranch'])) {
                continue;
            }

            $key = $this->getKeyForDep($composerName) ?? self::KEY_REQUIRE;

            $alias = $this->getCurrentComposerConstraint($composerName, $key);
            if (!$alias) {
                $alias = $parser->normalizeBranch($fork['baseBranch']);
            }
            if (str_starts_with($alias, '^') || str_starts_with($alias, '!')) {
                $alias = $parser->parseConstraints($alias)->getUpperBound()->getVersion();
            }
            $constraint = $parser->normalizeBranch($fork['prBranch']) . ' as ' . $alias;

            // Set dependency and repository info in composer.json
            $json[$key][$composerName] = $constraint;
        }

        $this->setComposerJson($json);
    }

    public function getCurrentComposerConstraint(string $dependency, string $key = self::KEY_REQUIRE): string|null
    {
        $json = $this->getComposerJson();

        if (!isset($json[$key][$dependency])) {
            return null;
        }
        return str_replace('/^.*? as /', '', $json[$key][$dependency]);
    }

    public function getKeyForDep(string $dependency): ?string
    {
        $json = $this->getComposerJson();
        foreach ([self::KEY_REQUIRE, self::KEY_REQUIRE_DEV] as $key) {
            if (isset($json[$key][$dependency])) {
                return $key;
            }
        }
        return null;
    }
}
