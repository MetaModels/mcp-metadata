<?php

declare(strict_types=1);

namespace MetaModels\McpMetadata;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

use function array_keys;
use function array_push;
use function basename;
use function file_get_contents;
use function glob;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function usort;

/**
 * Puts the source files together to the one file that is published: the handbook, one file per package (with the
 * types of the package) and one file per article. Checks the references between the parts and the result against the
 * schema.
 */
final class Builder
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * @return array{metadata: array<string, mixed>, errors: list<string>} The metadata is not complete if there are
     *                                                                       errors and must not be published.
     */
    public function build(): array
    {
        $errors   = [];
        $handbook = $this->read($this->root . '/data/handbook.json', $errors);
        [$packages, $types] = $this->packages($errors);
        $articles = $this->articles($errors);

        $metadata = [
            'schema'   => 1,
            'handbook' => $handbook,
            'packages' => $packages,
            'types'    => $types,
            'articles' => $articles,
        ];
        $this->checkReferences($metadata, $errors);
        if ([] === $errors) {
            array_push($errors, ...$this->validate($metadata));
        }

        return ['metadata' => $metadata, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return string The file as it is published: readable, or without any white space (about half the size) for a
     *                reader that has to take the whole file into its context.
     */
    public function encode(array $metadata, bool $minified = false): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return json_encode($metadata, $flags | ($minified ? 0 : JSON_PRETTY_PRINT)) . "\n";
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return list<string> The https addresses in the metadata, for the link check: the pages of the handbook in every
     *                      language, and the pages that tell how to get a package.
     */
    public function urls(array $metadata): array
    {
        $urls = [];
        $handbook = is_array($metadata['handbook'] ?? null) ? $metadata['handbook'] : [];
        $paths    = [];
        foreach ([...($metadata['packages'] ?? []), ...($metadata['types'] ?? [])] as $row) {
            $paths[] = is_array($row) ? ($row['docs'] ?? null) : null;
            $paths[] = is_array($row) && is_array($row['access'] ?? null) ? ($row['access']['page'] ?? null) : null;
        }
        foreach ($metadata['articles'] ?? [] as $article) {
            $paths[] = is_array($article) ? ($article['path'] ?? null) : null;
        }
        foreach (is_array($handbook['urls'] ?? null) ? $handbook['urls'] : [] as $base) {
            foreach ($paths as $path) {
                if (is_string($base) && is_string($path)) {
                    $urls[$base . $path] = true;
                }
            }
        }
        foreach ($metadata['packages'] ?? [] as $package) {
            foreach (is_array($package['access']['url'] ?? null) ? $package['access']['url'] : [] as $url) {
                if (is_string($url)) {
                    $urls[$url] = true;
                }
            }
        }
        ksort($urls);

        return array_keys($urls);
    }

    /**
     * @param list<string> $errors
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} The packages and the types.
     */
    private function packages(array &$errors): array
    {
        $packages = [];
        $types    = [];
        foreach (glob($this->root . '/data/packages/*/*.json') ?: [] as $file) {
            $row = $this->read($file, $errors);
            if ([] === $row) {
                continue;
            }

            $expected = basename(\dirname($file)) . '/' . basename($file, '.json');
            if (($row['name'] ?? null) !== $expected) {
                $errors[] = sprintf('%s: "name" has to be "%s".', $this->relative($file), $expected);
            }
            foreach (is_array($row['types'] ?? null) ? $row['types'] : [] as $type) {
                if (!is_array($type)) {
                    $errors[] = sprintf('%s: a type is not an object.', $this->relative($file));
                    continue;
                }
                $types[] = ['package' => $row['name'] ?? $expected] + $type;
            }
            unset($row['types']);
            $packages[] = $row;
        }

        usort($packages, static fn (array $left, array $right): int => ($left['name'] ?? '') <=> ($right['name'] ?? ''));
        usort(
            $types,
            static fn (array $left, array $right): int => [$left['kind'] ?? '', $left['type'] ?? '']
                <=> [$right['kind'] ?? '', $right['type'] ?? '']
        );

        return [$packages, $types];
    }

    /**
     * @param list<string> $errors
     *
     * @return list<array<string, mixed>>
     */
    private function articles(array &$errors): array
    {
        $articles = [];
        foreach (glob($this->root . '/data/articles/*.json') ?: [] as $file) {
            $row = $this->read($file, $errors);
            if ([] === $row) {
                continue;
            }
            if (($row['id'] ?? null) !== basename($file, '.json')) {
                $errors[] = sprintf('%s: "id" has to be "%s".', $this->relative($file), basename($file, '.json'));
            }
            $articles[] = $row;
        }

        usort($articles, static fn (array $left, array $right): int => ($left['id'] ?? '') <=> ($right['id'] ?? ''));

        return $articles;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<string>         $errors
     */
    private function checkReferences(array $metadata, array &$errors): void
    {
        $packages = [];
        foreach ($metadata['packages'] as $package) {
            $packages[$package['name'] ?? ''] = true;
        }

        foreach ($metadata['packages'] as $package) {
            $seen = [];
            foreach (is_array($package['lines'] ?? null) ? $package['lines'] : [] as $line) {
                $name = is_array($line) ? (string) ($line['metamodels'] ?? '') : '';
                if (isset($seen[$name])) {
                    $errors[] = sprintf('The package "%s" has the line "%s" twice.', $package['name'] ?? '?', $name);
                }
                $seen[$name] = true;
            }
        }

        $known = [];
        foreach ($metadata['types'] as $type) {
            $key = ($type['kind'] ?? '') . ':' . ($type['type'] ?? '');
            if (isset($known[$key])) {
                $errors[] = sprintf('The type "%s" is defined twice.', $key);
            }
            $known[$key] = true;
        }

        foreach ($metadata['types'] as $type) {
            $this->checkType($type, $known, $errors);
        }

        foreach ($metadata['articles'] as $article) {
            foreach (is_array($article['types'] ?? null) ? $article['types'] : [] as $ref) {
                $key = (is_array($ref) ? ($ref['kind'] ?? '') . ':' . ($ref['type'] ?? '') : '?');
                if (!isset($known[$key])) {
                    $errors[] = sprintf('The article "%s" is about the unknown type "%s".', $article['id'] ?? '?', $key);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $type
     * @param array<string, true>  $known The types by "kind:type".
     * @param list<string>         $errors
     */
    private function checkType(array $type, array $known, array &$errors): void
    {
        $name = ($type['kind'] ?? '') . ':' . ($type['type'] ?? '');

        foreach (is_array($type['works_with']['attribute_types'] ?? null) ? $type['works_with']['attribute_types'] : [] as $attribute) {
            if (!isset($known['attribute:' . $attribute])) {
                $errors[] = sprintf('The type "%s" works with the unknown attribute type "%s".', $name, $attribute);
            }
        }

        // The translated variants are the ones that keep a value per language, so the two must not disagree.
        $multilingual = $type['capabilities']['multilingual'] ?? null;
        if (null !== $multilingual && $multilingual !== str_starts_with((string) ($type['type'] ?? ''), 'translated')) {
            $errors[] = sprintf('The type "%s": multilingual does not match the name of the type.', $name);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return list<string>
     */
    private function validate(array $metadata): array
    {
        $schemaFile = $this->root . '/schema/metadata.schema.json';
        $schema     = is_file($schemaFile) ? json_decode((string) file_get_contents($schemaFile)) : null;
        if (null === $schema) {
            return ['schema/metadata.schema.json is missing or not valid JSON.'];
        }

        $result = (new Validator())->validate(json_decode((string) json_encode($metadata)), $schema);
        if ($result->isValid() || null === $result->error()) {
            return [];
        }

        $errors = [];
        foreach ((new ErrorFormatter())->format($result->error(), true) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = sprintf('%s: %s', $path, $message);
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    private function read(string $file, array &$errors): array
    {
        $content = is_file($file) ? file_get_contents($file) : false;
        $data    = false === $content ? null : json_decode($content, true);
        if (!is_array($data)) {
            $errors[] = sprintf('%s: missing or not a JSON object.', $this->relative($file));

            return [];
        }

        return $data;
    }

    private function relative(string $file): string
    {
        return str_ends_with($file, '.json') && str_starts_with($file, $this->root . '/')
            ? substr($file, \strlen($this->root) + 1)
            : $file;
    }
}
