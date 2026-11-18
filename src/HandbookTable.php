<?php

declare(strict_types=1);

namespace MetaModels\McpMetadata;

use DOMDocument;
use DOMElement;
use DOMXPath;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function mb_strlen;
use function preg_match;
use function preg_replace;
use function sort;
use function str_contains;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Reads the overview "Datentypen als Attribute anlegen" of the handbook: per attribute the filter rules, the support in
 * the frontend editing and the file usage. The page is the source for these facts, so the data of this repo is
 * compared against it (see HandbookCheck).
 */
final class HandbookTable
{
    /**
     * Names of the filter rules on the page => filter type. A name that is not listed here is an error, so a new rule
     * on the page does not go unnoticed.
     */
    private const FILTERS = [
        'Textsuche'                                     => 'text',
        'Einzelauswahl'                                 => 'select',
        'Mehrfachauswahl'                               => 'tags',
        'Einfache Abfrage'                              => 'simplelookup',
        'Register'                                      => 'register',
        'Levenshtein'                                   => 'levenshtein',
        'Loupe'                                         => 'loupe',
        'Wert von/bis für ein Attribut'                 => 'fromto',
        'Wert von/bis für zwei Attribute'               => 'range',
        'Wert von/bis für ein Datumsattribut'           => 'fromtodate',
        'Wert von/bis für zwei Datumsattribute'         => 'rangedate',
        'Umkreissuche'                                  => 'perimetersearch',
        'Ja / Nein'                                     => 'checkbox',
        'Checkbox-Status'                               => 'checkbox_published',
        'Übersetzter Checkbox-Status'                   => 'translatedcheckbox_published',
        'Filter auf Attribut des Modells mit einer Relation' => 'related',
    ];

    /**
     * @return array{types: array<string, array{fee: string, file_usage: bool, filters: list<string>}>, errors: list<string>}
     *         The attribute types by name; fee is "yes", "extra-package" or "no".
     */
    public function parse(string $html): array
    {
        $errors = [];
        $rows   = $this->rows($html);

        $types = [];
        foreach ($rows as $row) {
            if (null === $row['type']) {
                continue;
            }
            $types[$row['type']] = [
                'fee'        => $this->fee($row['fee'], $errors, $row['type']),
                'file_usage' => str_contains($row['note'], 'File-Usage ✔'),
                'filters'    => $this->filters($row['filters'], $rows, $errors, $row['type']),
            ];
        }
        $this->addSeparateColumns($rows, $types, $errors);

        return ['types' => $types, 'errors' => $errors];
    }

    /**
     * @return list<array{type: ?string, label: string, filters: string, fee: string, note: string}>
     */
    private function rows(string $html): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $rows = [];
        foreach ((new DOMXPath($document))->query('//table//tr') ?: [] as $tr) {
            if (!$tr instanceof DOMElement) {
                continue;
            }
            $cells = [];
            foreach ((new DOMXPath($document))->query('./td', $tr) ?: [] as $td) {
                $cells[] = $this->text($td->textContent);
            }
            if (\count($cells) < 6) {
                continue;
            }

            $rows[] = [
                'type'    => $this->typeName($cells[2]),
                'label'   => $cells[1],
                'filters' => $cells[3],
                'fee'     => $cells[4],
                'note'    => $cells[5],
            ];
        }

        return $rows;
    }

    private function typeName(string $package): ?string
    {
        if (!str_starts_with($package, 'attribute_')) {
            return null;
        }
        return substr($package, mb_strlen('attribute_'));
    }

    private function text(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param list<string> $errors
     */
    private function fee(string $cell, array &$errors, string $type): string
    {
        if (str_starts_with($cell, '✔')) {
            return 'yes';
        }
        if (str_starts_with($cell, '🗹')) {
            return 'extra-package';
        }
        if (str_starts_with($cell, '—')) {
            return 'no';
        }
        $errors[] = \sprintf('Handbook table: unknown frontend editing mark "%s" at "%s".', $cell, $type);

        return 'no';
    }

    /**
     * @param list<array{type: ?string, label: string, filters: string, fee: string, note: string}> $rows
     * @param list<string>                                                                          $errors
     *
     * @return list<string> Filter types, sorted.
     */
    private function filters(string $cell, array $rows, array &$errors, string $type, int $depth = 0): array
    {
        if ('' === $cell) {
            return [];
        }
        if (str_starts_with($cell, 'siehe ')) {
            $label = trim(substr($cell, mb_strlen('siehe ')));
            foreach ($rows as $row) {
                if ($row['label'] === $label && $depth < 2) {
                    return $this->filters($row['filters'], $rows, $errors, $type, $depth + 1);
                }
            }
            $errors[] = \sprintf('Handbook table: "%s" of "%s" points to no row.', $cell, $type);

            return [];
        }

        $filters = [];
        foreach (array_filter(array_map($this->text(...), explode(',', $cell))) as $name) {
            if (!isset(self::FILTERS[$name])) {
                $errors[] = \sprintf('Handbook table: unknown filter rule "%s" at "%s".', $name, $type);
                continue;
            }
            $filters[] = self::FILTERS[$name];
        }
        $filters = array_values(array_unique($filters));
        sort($filters);

        return $filters;
    }

    /**
     * A row without package that says "siehe <attribute>" in the attribute column (coordinates in two decimal
     * attributes) adds its filter rules to the attribute it points to.
     *
     * @param list<array{type: ?string, label: string, filters: string, fee: string, note: string}>                $rows
     * @param array<string, array{fee: string, file_usage: bool, filters: list<string>}> $types
     * @param list<string>                                                               $errors
     */
    private function addSeparateColumns(array $rows, array &$types, array &$errors): void
    {
        foreach ($rows as $row) {
            if (null !== $row['type'] || !str_starts_with($row['label'], 'siehe ') || '' === $row['filters']) {
                continue;
            }
            $label = trim(substr($row['label'], mb_strlen('siehe ')));
            $found = false;
            foreach ($rows as $target) {
                if ($target['label'] === $label && null !== $target['type']) {
                    $extra = $this->filters($row['filters'], $rows, $errors, $target['type']);
                    $merged = array_values(array_unique([...$types[$target['type']]['filters'], ...$extra]));
                    sort($merged);
                    $types[$target['type']]['filters'] = $merged;
                    $found = true;
                }
            }
            if (!$found) {
                $errors[] = \sprintf('Handbook table: "%s" points to no row.', $row['label']);
            }
        }
    }
}
