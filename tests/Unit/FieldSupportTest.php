<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Field\FieldSupport;

/**
 * {@see FieldSupport::requiredUnsupported()} — the rule behind the publish
 * guard (gh-59). It mirrors Joomla's own `FormField::validate()` emptiness test,
 * which is what a publish would otherwise hit as a 400 from the site.
 */
final class FieldSupportTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function field(string $name, string $type, bool $required = true, array $extra = []): array
    {
        return ['name' => $name, 'label' => ucfirst($name), 'type' => $type, 'required' => $required ? 1 : 0] + $extra;
    }

    /** `'0'` is a value — the same string Joomla's required check accepts. */
    public function testZeroIsAValue(): void
    {
        $split = (new FieldSupport())->requiredUnsupported(
            [$this->field('count', 'sql')],
            ['count' => '0'],
        );

        self::assertSame([], $split['blocking']);
        self::assertSame(['Count'], FieldSupport::labels($split['overridable']));
    }

    /** An empty string, a null and an empty array are all "no value". */
    public function testEmptyShapesAreNotValues(): void
    {
        $support = new FieldSupport();

        foreach (['' , null, []] as $empty) {
            $split = $support->requiredUnsupported(
                [$this->field('thing', 'subform')],
                ['thing' => $empty],
            );

            self::assertSame(['Thing'], FieldSupport::labels($split['blocking']));
            self::assertSame([], $split['overridable']);
        }
    }

    /** A multi-row value (an array of stored values) counts. */
    public function testNonEmptyArrayIsAValue(): void
    {
        $split = (new FieldSupport())->requiredUnsupported(
            [$this->field('owners', 'user')],
            ['owners' => ['42', '43']],
        );

        self::assertSame([], $split['blocking']);
        self::assertCount(1, $split['overridable']);
    }

    /** Neither a supported type nor an optional one is ever reported. */
    public function testSupportedAndOptionalFieldsAreIgnored(): void
    {
        $split = (new FieldSupport())->requiredUnsupported(
            [
                $this->field('subtitle', 'text'),
                $this->field('blurb', 'editor', required: false),
            ],
            [],
        );

        self::assertSame(['blocking' => [], 'overridable' => []], $split);
    }

    /** A field with no label falls back to its name rather than to nothing. */
    public function testLabelsFallBackToTheFieldName(): void
    {
        self::assertSame(['owner'], FieldSupport::labels([['name' => 'owner', 'type' => 'user']]));
        self::assertSame(['owner'], FieldSupport::names([['name' => 'owner', 'type' => 'user']]));
    }
}
