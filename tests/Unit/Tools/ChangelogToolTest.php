<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../tools/changelog.php';

final class ChangelogToolTest extends TestCase
{
    private const CHANGELOG = <<<'MD'
        # Changelog

        ## [Unreleased]

        ## [0.2.0] - 2026-02-01

        ### Added

        - Two.

        ## [0.1.0] - 2026-01-01

        - One.

        [Unreleased]: https://example.test/compare/v0.2.0...HEAD
        [0.1.0]: https://example.test/releases/tag/v0.1.0
        MD;

    public function testASectionIsReturnedWithoutItsHeadingOrTheNeighbours(): void
    {
        self::assertSame("### Added\n\n- Two.", changelogSection(self::CHANGELOG, '0.2.0'));
        self::assertSame('- One.', changelogSection(self::CHANGELOG, '0.1.0'), 'The link references at the end are not part of it.');
    }

    public function testMissingOrEmptySectionsGiveNull(): void
    {
        self::assertNull(changelogSection(self::CHANGELOG, '9.9.9'));
        self::assertNull(changelogSection(self::CHANGELOG, 'Unreleased'), 'An empty section is no release notes.');
    }

    public function testEveryReleasedVersionOfTheRealChangelogHasNotes(): void
    {
        $changelog = (string) file_get_contents(__DIR__ . '/../../../CHANGELOG.md');
        preg_match_all('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $versions);

        self::assertNotSame([], $versions[1]);
        foreach ($versions[1] as $version) {
            self::assertNotNull(changelogSection($changelog, $version), 'No notes for ' . $version);
        }
    }
}
