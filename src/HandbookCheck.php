<?php

declare(strict_types=1);

namespace MetaModels\McpMetadata;

use function implode;
use function is_array;
use function sort;
use function sprintf;

/**
 * Compares the data of this repo with the overview of the handbook (HandbookTable): frontend editing, file usage and
 * the attributes a filter rule works with. Differences are errors, the page and the data must tell the same.
 */
final class HandbookCheck
{
    /**
     * @param array<string, mixed> $metadata The built metadata.
     * @param array<string, array{fee: string, file_usage: bool, filters: list<string>}> $table
     *
     * @return list<string>
     */
    public function compare(array $metadata, array $table): array
    {
        $errors  = [];
        $catalog = $this->index($metadata);

        $expectedWorksWith = [];
        foreach ($table as $name => $row) {
            $type = $catalog['attribute:' . $name] ?? null;
            if (null === $type) {
                $errors[] = sprintf('The handbook table lists the attribute "%s", the data has no such type.', $name);
                continue;
            }
            $this->compareAttribute($name, $row, $type, $errors);
            foreach ($row['filters'] as $filter) {
                $expectedWorksWith[$filter][] = $name;
            }
        }

        foreach ($expectedWorksWith as $filter => $attributes) {
            sort($attributes);
            $type = $catalog['filter:' . $filter] ?? null;
            $given = is_array($type['works_with']['attribute_types'] ?? null) ? $type['works_with']['attribute_types'] : null;
            if (null === $type) {
                $errors[] = sprintf('The handbook table names the filter "%s", the data has no such type.', $filter);
            } elseif (null === $given) {
                $errors[] = sprintf('Filter "%s": works_with is missing, the handbook says: %s.', $filter, implode(', ', $attributes));
            } else {
                sort($given);
                if ($given !== $attributes) {
                    $errors[] = sprintf(
                        'Filter "%s": works_with is [%s], the handbook says [%s].',
                        $filter,
                        implode(', ', $given),
                        implode(', ', $attributes)
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * @param array{fee: string, file_usage: bool, filters: list<string>} $row
     * @param array<string, mixed>                                        $type
     * @param list<string>                                                $errors
     */
    private function compareAttribute(string $name, array $row, array $type, array &$errors): void
    {
        $capabilities = $type['capabilities'] ?? null;
        if (!is_array($capabilities)) {
            $errors[] = sprintf('Attribute "%s": capabilities are missing.', $name);

            return;
        }

        $support = $capabilities['frontend_editing']['support'] ?? null;
        if ($support !== $row['fee']) {
            $errors[] = sprintf('Attribute "%s": frontend_editing is "%s", the handbook says "%s".', $name, $support ?? '-', $row['fee']);
        }
        if (($capabilities['file_usage'] ?? false) !== $row['file_usage']) {
            $errors[] = sprintf('Attribute "%s": file_usage differs from the handbook (%s).', $name, $row['file_usage'] ? 'true' : 'false');
        }
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, array<string, mixed>> The types by "kind:type".
     */
    private function index(array $metadata): array
    {
        $index = [];
        foreach ($metadata['types'] ?? [] as $type) {
            if (is_array($type)) {
                $index[($type['kind'] ?? '') . ':' . ($type['type'] ?? '')] = $type;
            }
        }

        return $index;
    }
}
