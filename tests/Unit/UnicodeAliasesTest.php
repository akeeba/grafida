<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Site\UnicodeAliases;

/**
 * gh-61: the per-site "Site uses Unicode Aliases" tri-state.
 *
 * The normaliser is the only thing between a TEXT column that may hold
 * anything — including NULL, on every row written before the migration — and
 * code that branches on three known values, so what it does with an
 * unrecognised value is the whole contract.
 */
final class UnicodeAliasesTest extends TestCase
{
    public function testEveryKnownValueSurvives(): void
    {
        self::assertSame('auto', UnicodeAliases::normalise('auto'));
        self::assertSame('yes', UnicodeAliases::normalise('yes'));
        self::assertSame('no', UnicodeAliases::normalise('no'));
    }

    public function testCaseAndSurroundingSpaceDoNotMatter(): void
    {
        self::assertSame('yes', UnicodeAliases::normalise('  YES '));
        self::assertSame('no', UnicodeAliases::normalise('No'));
    }

    /**
     * NULL is what a row written before the migration holds, and '' is what a
     * form that did not send the field posts. Both must read as "ask the site",
     * i.e. exactly what every site did before this setting existed — anything
     * else would silently change the alias algorithm on upgrade.
     */
    public function testAnythingUnrecognisedFallsBackToAutomatic(): void
    {
        self::assertSame('auto', UnicodeAliases::normalise(null));
        self::assertSame('auto', UnicodeAliases::normalise(''));
        self::assertSame('auto', UnicodeAliases::normalise('maybe'));
        self::assertSame('auto', UnicodeAliases::normalise('1'));
    }

    /** The SPA's UNICODE_ALIAS_CHOICES offers exactly these, in this order. */
    public function testTheAvailableListIsTheThreeChoicesInFormOrder(): void
    {
        self::assertSame(['auto', 'yes', 'no'], UnicodeAliases::AVAILABLE);
    }
}
