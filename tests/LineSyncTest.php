<?php

declare(strict_types=1);

namespace MetaModels\McpMetadata\Test;

use MetaModels\McpMetadata\LineSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_keys;
use function escapeshellarg;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function shell_exec;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(LineSync::class)]
final class LineSyncTest extends TestCase
{
    private const CONFIG = [
        'lines'     => ['2.4', '2.6'],
        'ref'       => 'feature/%s.0',
        'requires'  => ['php', 'contao/core-bundle', 'metamodels/'],
        'overrides' => ['metamodels/special' => ['2.6' => ['release/2.6']]],
    ];

    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcp-lines-' . uniqid('', true);
        mkdir($this->dir, 0777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * @param array<string, string> $require
     */
    private function commit(string $package, string $branch, array $require): void
    {
        $directory = $this->dir . '/' . $package;
        if (!is_dir($directory)) {
            mkdir($directory);
            shell_exec('git -C ' . escapeshellarg($directory) . ' init -q 2>&1');
        }
        shell_exec('git -C ' . escapeshellarg($directory) . ' checkout -q -B ' . escapeshellarg($branch) . ' 2>&1');
        file_put_contents($directory . '/composer.json', json_encode(['require' => $require]));
        shell_exec(
            'git -C ' . escapeshellarg($directory) . ' add composer.json && git -C ' . escapeshellarg($directory)
            . ' -c user.name=t -c user.email=t@example.org commit -q -m x 2>&1'
        );
    }

    public function testReadsTheRequirementsOfEachLineFromItsBranch(): void
    {
        $this->commit('attribute_text', 'feature/2.4.0', ['php' => '^8.2', 'contao/core-bundle' => '^5.3 <5.7', 'symfony/config' => '^6.4']);
        $this->commit('attribute_text', 'feature/2.6.0', ['php' => '^8.4', 'contao/core-bundle' => '^6.0', 'metamodels/core' => '^2.6@dev']);

        self::assertSame(
            [
                ['metamodels' => '2.4', 'requires' => ['contao/core-bundle' => '^5.3 <5.7', 'php' => '^8.2']],
                ['metamodels' => '2.6', 'requires' => ['contao/core-bundle' => '^6.0', 'metamodels/core' => '^2.6@dev', 'php' => '^8.4']],
            ],
            (new LineSync($this->dir, self::CONFIG))->lines('metamodels/attribute_text')
        );
    }

    public function testAPackageWithoutTheBranchOfALineDoesNotHaveTheLine(): void
    {
        $this->commit('attribute_new', 'feature/2.6.0', ['php' => '^8.4']);

        self::assertSame(['2.6'], array_column((new LineSync($this->dir, self::CONFIG))->lines('metamodels/attribute_new'), 'metamodels'));
        self::assertSame([], (new LineSync($this->dir, self::CONFIG))->lines('metamodels/no_clone'));
    }

    public function testAnOverrideNamesTheBranchOfALine(): void
    {
        $this->commit('special', 'release/2.6', ['php' => '^8.4']);

        self::assertSame(['2.6'], array_column((new LineSync($this->dir, self::CONFIG))->lines('metamodels/special'), 'metamodels'));
    }

    public function testMergePutsTheLinesInFrontOfTheTypesAndKeepsTheAvailability(): void
    {
        $sync    = new LineSync($this->dir, self::CONFIG);
        $package = [
            'name'  => 'metamodels/a',
            'lines' => [['metamodels' => '2.4', 'availability' => 'early-access', 'requires' => ['php' => '^7']]],
            'types' => [],
        ];
        $lines = [
            ['metamodels' => '2.4', 'requires' => ['php' => '^8.2']],
            ['metamodels' => '2.6', 'requires' => ['php' => '^8.4']],
        ];

        $merged = $sync->merge($package, $lines, null);

        self::assertSame(['name', 'lines', 'types'], array_keys($merged));
        self::assertSame(
            [
                ['metamodels' => '2.4', 'availability' => 'early-access', 'requires' => ['php' => '^8.2']],
                ['metamodels' => '2.6', 'requires' => ['php' => '^8.4']],
            ],
            $merged['lines']
        );
    }

    public function testMergeMarksTheLinesWithAReleaseOnPackagistAsPublic(): void
    {
        $lines  = [['metamodels' => '2.4', 'requires' => []], ['metamodels' => '2.6', 'requires' => []]];
        $merged = (new LineSync($this->dir, self::CONFIG))->merge(['name' => 'a', 'types' => []], $lines, ['2.4']);

        self::assertSame(['public', null], [$merged['lines'][0]['availability'], $merged['lines'][1]['availability'] ?? null]);
    }

    public function testMergeDropsTheLinesOfAPackageThatHasNone(): void
    {
        $merged = (new LineSync($this->dir, self::CONFIG))->merge(['name' => 'a', 'lines' => [['x']], 'types' => []], [], null);

        self::assertSame(['name', 'types'], array_keys($merged));
    }
}
