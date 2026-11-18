<?php

declare(strict_types=1);

namespace MetaModels\McpMetadata\Test;

use MetaModels\McpMetadata\HandbookTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_get_contents;

#[CoversClass(HandbookTable::class)]
final class HandbookTableTest extends TestCase
{
    /**
     * @return array{types: array<string, array{fee: string, file_usage: bool, filters: list<string>}>, errors: list<string>}
     */
    private function parse(?string $html = null): array
    {
        return (new HandbookTable())->parse($html ?? (string) file_get_contents(__DIR__ . '/fixtures/data-in-attributes.html'));
    }

    public function testReadsFilterRulesFrontendEditingAndFileUsage(): void
    {
        $result = $this->parse();

        self::assertSame([], $result['errors']);
        self::assertSame(
            ['fee' => 'yes', 'file_usage' => false, 'filters' => ['select', 'text']],
            $result['types']['text']
        );
        self::assertSame(['fee' => 'yes', 'file_usage' => true, 'filters' => ['text']], $result['types']['longtext']);
        self::assertSame('extra-package', $result['types']['url']['fee']);
    }

    public function testResolvesTheReferenceToAnotherRow(): void
    {
        self::assertSame(['select', 'text'], $this->parse()['types']['translatedtext']['filters']);
    }

    public function testAddsTheRuleOfARowWithSeparateColumnsToTheAttributeItPointsTo(): void
    {
        $types = $this->parse()['types'];

        self::assertContains('perimetersearch', $types['decimal']['filters']);
        self::assertSame('no', $types['latlong']['fee']);
    }

    public function testIgnoresRowsOfOtherPackagesAndSectionRows(): void
    {
        self::assertSame(
            ['text', 'longtext', 'url', 'translatedtext', 'translatedurl', 'decimal', 'latlong'],
            \array_keys($this->parse()['types'])
        );
    }

    public function testAFilterRuleOrMarkThePageDoesNotKnowIsAnError(): void
    {
        $html = '<table><tr><td>x</td><td>Text</td><td>attribute_text</td><td>Neue Regel</td><td>?</td><td></td></tr></table>';

        self::assertSame(
            [
                'Handbook table: unknown frontend editing mark "?" at "text".',
                'Handbook table: unknown filter rule "Neue Regel" at "text".',
            ],
            $this->parse($html)['errors']
        );
    }
}
