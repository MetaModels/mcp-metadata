<?php

declare(strict_types=1);

namespace MetaModels\McpMetadata\Test;

use MetaModels\McpMetadata\HandbookCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HandbookCheck::class)]
final class HandbookCheckTest extends TestCase
{
    /**
     * @return array{0: array<string, mixed>, 1: array<string, array{fee: string, file_usage: bool, filters: list<string>}>}
     */
    private function fixture(): array
    {
        $metadata = ['types' => [
            [
                'kind'         => 'attribute',
                'type'         => 'text',
                'capabilities' => ['file_usage' => false, 'frontend_editing' => ['support' => 'yes']],
            ],
            ['kind' => 'filter', 'type' => 'select', 'works_with' => ['attribute_types' => ['text']]],
        ]];
        $table = ['text' => ['fee' => 'yes', 'file_usage' => false, 'filters' => ['select']]];

        return [$metadata, $table];
    }

    public function testAcceptsDataThatMatchesThePage(): void
    {
        [$metadata, $table] = $this->fixture();

        self::assertSame([], (new HandbookCheck())->compare($metadata, $table));
    }

    public function testReportsEveryKindOfDifference(): void
    {
        [$metadata, $table] = $this->fixture();
        $table['text'] = ['fee' => 'extra-package', 'file_usage' => true, 'filters' => ['select', 'tags']];
        $table['color'] = ['fee' => 'yes', 'file_usage' => false, 'filters' => []];

        self::assertSame(
            [
                'Attribute "text": frontend_editing is "yes", the handbook says "extra-package".',
                'Attribute "text": file_usage differs from the handbook (true).',
                'The handbook table lists the attribute "color", the data has no such type.',
                'The handbook table names the filter "tags", the data has no such type.',
            ],
            (new HandbookCheck())->compare($metadata, $table)
        );
    }

    public function testReportsMissingCapabilitiesAndMissingWorksWith(): void
    {
        [$metadata, $table] = $this->fixture();
        unset($metadata['types'][0]['capabilities'], $metadata['types'][1]['works_with']);

        self::assertSame(
            [
                'Attribute "text": capabilities are missing.',
                'Filter "select": works_with is missing, the handbook says: text.',
            ],
            (new HandbookCheck())->compare($metadata, $table)
        );
    }

    public function testReportsWorksWithThatDiffers(): void
    {
        [$metadata, $table] = $this->fixture();
        $metadata['types'][1]['works_with']['attribute_types'] = ['text', 'longtext'];

        self::assertSame(
            ['Filter "select": works_with is [longtext, text], the handbook says [text].'],
            (new HandbookCheck())->compare($metadata, $table)
        );
    }
}
