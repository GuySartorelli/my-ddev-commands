<?php declare(strict_types=1);

namespace GuySartorelli\DdevPhpUtils;

use Github\AuthMethod;
use Github\Client as GithubClient;
use Github\Exception\RuntimeException as GitHubRuntimeException;
use InvalidArgumentException;
use RuntimeException;

final class GitHubService
{
    /**
     * Get the full set of details of a GitHub repository from a URL or org/repo#123 formatted string.
     *
     * If the identifier includes a pull request reference, details about the pull request are aso included.
     */
    public static function getRepositoryDetails(string $repoIdentifier, string $githubToken = ''): array
    {
        $client = new GithubClient();
        if ($githubToken) {
            $client->authenticate($githubToken, AuthMethod::ACCESS_TOKEN);
        }
        $parsed = self::parseIdentifier($repoIdentifier);
        $nameForOutput = "{$parsed['org']}/{$parsed['repo']}";
        try {
            $nameForOutput = self::getComposerNameForIdentifier($client, $parsed);
        } catch (RuntimeException) {}
        return [
            ...$parsed,
            'outputName' => $nameForOutput,
            'cloneUri' => "git@github.com:{$parsed['org']}/{$parsed['repo']}.git",
            'pr' => isset($parsed['pr']) ? self::getPRDetails($client, $parsed) : null,
        ];
    }

    /**
     * Get the full set of details of a GitHub pull request from an array of PR URLs or org/repo#123 formatted strings.
     */
    public static function getPullRequestDetails(array $rawPRs, string $githubToken = '', bool $allowNonPr = false): array
    {
        if (empty($rawPRs)) {
            return [];
        }
        $client = new GithubClient();
        if ($githubToken) {
            $client->authenticate($githubToken, AuthMethod::ACCESS_TOKEN);
        }
        $prs = [];
        foreach ($rawPRs as $rawPR) {
            $parsed = self::parseIdentifier($rawPR);
            $composerName = self::getComposerNameForIdentifier($client, $parsed);

            if (empty($parsed['pr'])) {
                // If this is a fork but not a PR, we only need the parsed details and the remote details.
                if ($allowNonPr) {
                    $remote = "git@github.com:{$parsed['org']}/{$parsed['repo']}.git";
                    $prs[$composerName] = array_merge($parsed, [
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

            $prs[$composerName] = self::getPRDetails($client, $parsed);
        }
        return $prs;
    }

    /**
     * Parse a URL or github-shorthand repository reference (with optional PR) into an array containing the org, repo, and pr components.
     */
    private static function parseIdentifier(string $identifier): array
    {
        $identifier = preg_replace('#^(https?://(www\.)?github\.com/|git@github\.com:)#', '', $identifier);
        if (!preg_match('@(?<org>[a-zA-Z0-9_-]*)/(?<repo>[a-zA-Z0-9._-]*)(?>(?>/pull/|#)(?<pr>[0-9]+))?@', $identifier, $matches)) {
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
    private static function getComposerNameForIdentifier(GithubClient $client, array $parsedIdentifier): string
    {
        try {
            $composerJson = $client->repo()->contents()->download($parsedIdentifier['org'], $parsedIdentifier['repo'], 'composer.json');
        } catch (GitHubRuntimeException $e) {
            throw new RuntimeException("Couldn't find composer name for {$parsedIdentifier['org']}/{$parsedIdentifier['repo']}: {$e->getMessage()}");
        }
        $composerName = json_decode($composerJson, true)['name'] ?? '';
        if (!$composerName) {
            throw new RuntimeException("Couldn't find composer name for {$parsedIdentifier['org']}/{$parsedIdentifier['repo']}: No 'name' key in composer.json file");
        }
        return $composerName;
    }

    /**
     * Get details about the PR from the github API
     */
    private static function getPRDetails(GithubClient $client, array $parsedIdentifier): array
    {
        $prDetails = $client->pullRequest()->show($parsedIdentifier['org'], $parsedIdentifier['repo'], $parsedIdentifier['pr']);
        $remote = $prDetails['head']['repo']['ssh_url'];
        $remoteName = self::getNameForRemote($remote);

        return array_merge($parsedIdentifier, [
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
}
