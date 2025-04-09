<?php declare(strict_types=1);

namespace GuySartorelli\DdevPhpUtils;

use Github\AuthMethod;
use Github\Client as GithubClient;
use Github\Exception\RuntimeException as GitHubRuntimeException;
use InvalidArgumentException;
use RuntimeException;

final class GitHubService
{
    private static ?GithubClient $client = null;

    private static array $composerDetails = [];

    /**
     * Get the composer.json contents for a GitHub repository from a URL or org/repo formatted string.
     * @throws RuntimeException if the file can't be fetched or is invalid JSON.
     */
    public static function getComposerJsonForIdentifier(string $repoIdentifier, ?string $branch = null): \stdClass
    {
        if (array_key_exists($repoIdentifier, self::$composerDetails)) {
            return self::$composerDetails[$repoIdentifier];
        }
        $client = self::getClient();
        $parsedIdentifier = self::parseIdentifier($repoIdentifier);

        try {
            $composerJson = $client->repo()->contents()->download($parsedIdentifier['org'], $parsedIdentifier['repo'], 'composer.json', $branch);
        } catch (GitHubRuntimeException $e) {
            throw new RuntimeException("Couldn't find composer.json for {$parsedIdentifier['org']}/{$parsedIdentifier['repo']}: {$e->getMessage()}");
        }

        $json = json_decode($composerJson, false);
        if ($json === null) {
            $error = json_last_error_msg();
            throw new RuntimeException("Composer.json wasn't correctly parsed for {$parsedIdentifier['org']}/{$parsedIdentifier['repo']}: $error");
        }
        self::$composerDetails[$repoIdentifier] = $json;
        return $json;
    }

    /**
     * Get the full set of details of a GitHub repository from a URL or org/repo#123 formatted string.
     *
     * If the identifier includes a pull request reference, details about the pull request are aso included.
     */
    public static function getRepositoryDetails(string $repoIdentifier): array
    {
        $parsed = self::parseIdentifier($repoIdentifier);
        $nameForOutput = "{$parsed['org']}/{$parsed['repo']}";
        $type = null;
        try {
            $nameForOutput = self::getComposerNameForIdentifier($repoIdentifier);
            $type = self::getComposerJsonForIdentifier($repoIdentifier)->type ?? null;
        } catch (RuntimeException) {}
        return [
            ...$parsed,
            'type' => $type,
            'outputName' => $nameForOutput,
            'cloneUri' => "git@github.com:{$parsed['org']}/{$parsed['repo']}.git",
            'pr' => isset($parsed['pr']) ? self::getPRDetails($parsed) : null,
        ];
    }

    /**
     * Get the full set of details of a GitHub pull request from an array of PR URLs or org/repo#123 formatted strings.
     */
    public static function getPullRequestDetails(array $rawPRs, bool $allowNonPr = false): array
    {
        if (empty($rawPRs)) {
            return [];
        }

        $prs = [];
        foreach ($rawPRs as $rawPR) {
            $parsed = self::parseIdentifier($rawPR);
            $composerName = self::getComposerNameForIdentifier($rawPR);

            if (empty($parsed['pr'])) {
                // If this is a fork but not a PR, we only need the parsed details and the remote details.
                if ($allowNonPr) {
                    $remote = "git@github.com:{$parsed['org']}/{$parsed['repo']}.git";
                    $type = self::getComposerJsonForIdentifier($composerName)->type ?? null;
                    $prs[$composerName] = array_merge($parsed, [
                        'type' => $type,
                        'remote' => $remote,
                        'remoteName' => self::getNameForRemote($remote),
                    ]);
                    continue;
                }
                // Usually we explicitly want PRs only.
                throw new InvalidArgumentException("'$rawPR' is not a valid GitHub PR reference.");
            }

            if (array_key_exists($composerName, $prs)) {
                throw new InvalidArgumentException("cannot add multiple PRs for the same package: $composerName");
            }

            $prs[$composerName] = self::getPRDetails($parsed);
        }
        return $prs;
    }

    /**
     * Parse a URL or github-shorthand repository reference (with optional PR or branch) into an array containing the org, repo, and pr or branch components.
     */
    private static function parseIdentifier(string $identifier): array
    {
        $identifier = preg_replace('#^(https?://(www\.)?github\.com/|git@github\.com:)#', '', $identifier);
        if (!preg_match('@(?<org>[a-zA-Z0-9_-]*)/(?<repo>[a-zA-Z0-9._-]*)(?>(?>/pull/|#)(?<pr>[0-9]+)|(?>/tree/)(?<branch>[^/\s]+))?@', $identifier, $matches)) {
            throw new InvalidArgumentException("'$identifier' is not a valid GitHub repository reference.");
        }
        if (empty($matches['org']) || empty($matches['repo'])) {
            throw new InvalidArgumentException("'$identifier' is not a valid GitHub repository reference.");
        }
        return $matches;
    }

    /**
     * Get the composer name of a project from the composer.json of a repo.
     */
    private static function getComposerNameForIdentifier(string $repoIdentifier): string
    {
        $composerJson = self::getComposerJsonForIdentifier($repoIdentifier);
        $composerName = $composerJson->name ?? '';
        if (!$composerName) {
            $parsedIdentifier = self::parseIdentifier($repoIdentifier);
            throw new RuntimeException("Couldn't find composer name for {$parsedIdentifier['org']}/{$parsedIdentifier['repo']}: No 'name' key in composer.json file");
        }
        return $composerName;
    }

    /**
     * Get details about the PR from the github API
     */
    private static function getPRDetails(array $parsedIdentifier): array
    {
        $client = self::getClient();
        $prDetails = $client->pullRequest()->show($parsedIdentifier['org'], $parsedIdentifier['repo'], $parsedIdentifier['pr']);
        $remote = $prDetails['head']['repo']['ssh_url'];
        $remoteName = self::getNameForRemote($remote);
        $type = self::getComposerJsonForIdentifier("{$parsedIdentifier['org']}/{$parsedIdentifier['repo']}")->type ?? null;

        return array_merge($parsedIdentifier, [
            'type' => $type,
            'from-org' => $prDetails['head']['user']['login'],
            'remote' => $remote,
            'prBranch' => $prDetails['head']['ref'],
            'baseBranch' => $prDetails['base']['ref'],
            'remoteName' => $remoteName,
        ]);
    }

    /**
     * Get a name for the remote if we end up using it in git
     */
    private static function getNameForRemote(string $remote): string
    {
        // Check PR type to determine remote name
        $prIsCC = str_starts_with($remote, 'git@github.com:creative-commoners/');
        $prIsSecurity = str_starts_with($remote, 'git@github.com:silverstripe-security/');
        if ($prIsCC) {
            return 'cc';
        }
        if ($prIsSecurity) {
            return 'security';
        }
        return 'pr';
    }

    private static function getClient(): GithubClient
    {
        if (self::$client === null) {
            self::$client = new GithubClient();
            $githubToken = DDevHelper::getCustomConfig('github_token');
            if ($githubToken) {
                self::$client->authenticate($githubToken, AuthMethod::ACCESS_TOKEN);
            }
        }
        return self::$client;
    }
}
