<?php

declare(strict_types=1);

namespace MetaModels\McpMetadata;

use function array_key_last;
use function array_unique;
use function array_values;
use function basename;
use function escapeshellarg;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function ksort;
use function preg_match;
use function shell_exec;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stream_context_create;
use function usort;
use function version_compare;

/**
 * Reads what each release line of MetaModels needs from the composer.json of a package, in the clone of the package.
 * The lines are the branches (feature/2.4.0, ...), so the values come from a source that can be named, not from memory.
 */
final class LineSync
{
    /** Remotes the clones use; the local branch is looked at first, it is the state the maintainer works with. */
    private const SOURCES = ['', 'origin/', 'origin-github/', 'origin-gitlab/'];

    /**
     * @param array{lines: list<string>, ref: string, requires: list<string>, overrides: array<string, array<string, list<string>>>} $config
     */
    public function __construct(private readonly string $packagesDir, private readonly array $config)
    {
    }

    /**
     * @return list<array{metamodels: string, requires: array<string, string>}> The lines the package has, oldest first.
     */
    public function lines(string $package): array
    {
        $directory = $this->packagesDir . '/' . basename($package);
        if (!is_dir($directory)) {
            return [];
        }

        $lines = [];
        foreach ($this->config['lines'] as $line) {
            $require = $this->require($directory, $this->refs($package, $line));
            if (null !== $require) {
                $lines[] = ['metamodels' => $line, 'requires' => $this->relevant($require)];
            }
        }

        return $lines;
    }

    /**
     * Puts the lines into the package, in front of its types. A line keeps the availability it had; with the lines that
     * have a release on Packagist the availability becomes `public`. If Packagist was asked, an older line without a
     * release there is `early-access`: what is not public for an older line is only available in the early access.
     * The newest line has no own availability, the one of the package is its availability.
     *
     * @param array<string, mixed>                                              $package
     * @param list<array{metamodels: string, requires: array<string, string>}> $lines
     * @param list<string>|null                                                 $public  Null if Packagist was not asked.
     *
     * @return array<string, mixed>
     */
    public function merge(array $package, array $lines, ?array $public): array
    {
        $known = [];
        foreach (is_array($package['lines'] ?? null) ? $package['lines'] : [] as $line) {
            if (is_array($line) && isset($line['metamodels'], $line['availability'])) {
                $known[$line['metamodels']] = $line['availability'];
            }
        }

        $merged = [];
        foreach ($lines as $line) {
            $availability = $this->availability($line['metamodels'], $known[$line['metamodels']] ?? null, $public);
            $merged[] = ['metamodels' => $line['metamodels']]
                + (null === $availability ? [] : ['availability' => $availability])
                + ['requires' => $line['requires']];
        }

        unset($package['lines']);
        if ([] === $merged) {
            return $package;
        }

        $result = [];
        foreach ($package as $key => $value) {
            if ('types' === $key) {
                $result['lines'] = $merged;
            }
            $result[$key] = $value;
        }

        return $result + ['lines' => $merged];
    }

    /**
     * @param array<string, mixed> $require
     *
     * @return array<string, string> Only what decides whether the package fits (PHP, Contao, MetaModels and the
     *                               libraries of the Community Alliance), the framework packages are no help for that.
     */
    public function relevant(array $require): array
    {
        $result = [];
        foreach ($require as $name => $constraint) {
            if (is_string($constraint) && $this->isRelevant((string) $name)) {
                $result[(string) $name] = $constraint;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * @return list<string>|null The lines (2.4, 2.5, ...) with a stable release of the package on Packagist, which
     *                            means `composer require` works for everybody. Empty if the package is not there,
     *                            null if Packagist did not answer (so nothing is derived from a network error).
     */
    public function publicLines(string $package): ?array
    {
        $json = @file_get_contents(
            'https://repo.packagist.org/p2/' . $package . '.json',
            false,
            stream_context_create(['http' => ['timeout' => 20]])
        );
        if (false === $json) {
            return $this->notFound($http_response_header ?? []) ? [] : null;
        }
        $data = json_decode($json, true);
        $versions = is_array($data) && is_array($data['packages'][$package] ?? null) ? $data['packages'][$package] : [];

        $lines = [];
        foreach ($versions as $version) {
            $number = is_array($version) ? ($version['version'] ?? null) : null;
            if (is_string($number) && 1 === preg_match('~^v?(\d+\.\d+)\.\d+$~', $number, $match)) {
                $lines[] = $match[1];
            }
        }
        $lines = array_values(array_unique($lines));
        usort($lines, static fn (string $a, string $b): int => version_compare($a, $b));

        return $lines;
    }

    /**
     * @param list<string> $headers
     */
    private function notFound(array $headers): bool
    {
        return isset($headers[0]) && str_contains($headers[0], ' 404');
    }

    /**
     * @param list<string>|null $public
     */
    private function availability(string $line, ?string $known, ?array $public): ?string
    {
        if (null === $public) {
            return $known;
        }
        if (in_array($line, $public, true)) {
            return 'public';
        }

        return $known ?? ($line === $this->config['lines'][array_key_last($this->config['lines'])] ? null : 'early-access');
    }

    private function isRelevant(string $name): bool
    {
        foreach ($this->config['requires'] as $rule) {
            if ($name === $rule || (str_ends_with($rule, '/') && str_starts_with($name, $rule))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> The refs to try for the line of a package.
     */
    private function refs(string $package, string $line): array
    {
        $own = $this->config['overrides'][$package][$line] ?? null;
        $refs = [] === ($own ?? []) ? [sprintf($this->config['ref'], $line)] : $own;

        $candidates = [];
        foreach ($refs as $ref) {
            foreach (self::SOURCES as $source) {
                $candidates[] = $source . $ref;
            }
        }

        return $candidates;
    }

    /**
     * @param list<string> $refs
     *
     * @return array<string, mixed>|null
     */
    private function require(string $directory, array $refs): ?array
    {
        foreach ($refs as $ref) {
            $json = shell_exec(sprintf(
                'git -C %s show %s 2>/dev/null',
                escapeshellarg($directory),
                escapeshellarg($ref . ':composer.json')
            ));
            $data = is_string($json) ? json_decode($json, true) : null;
            if (is_array($data) && is_array($data['require'] ?? null)) {
                return $data['require'];
            }
        }

        return null;
    }
}
