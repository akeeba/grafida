<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Field\FieldCategoryScope;

/**
 * Custom fields are per-category in Joomla: the article form only ever shows
 * the fields assigned to the article's category (or to one of its ancestors,
 * or to no category at all). Grafida used to show every field of the site,
 * which put fields from unrelated categories in the sidebar and — when one of
 * them was required — blocked publishing outright.
 */
final class FieldCategoryScopeTest extends TestCase
{
    private FieldCategoryScope $scope;

    protected function setUp(): void
    {
        $this->scope = new FieldCategoryScope();
    }

    /**
     * A three-level tree: Blog (10) → Blog/Tutorials (11) → Blog/Tutorials/PHP (12),
     * plus an unrelated Projects (20).
     *
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        return [
            ['id' => 10, 'parent_id' => 1,  'title' => 'Blog'],
            ['id' => 11, 'parent_id' => 10, 'title' => 'Tutorials'],
            ['id' => 12, 'parent_id' => 11, 'title' => 'PHP'],
            ['id' => 20, 'parent_id' => 1,  'title' => 'Projects'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<string>
     */
    private function names(array $fields): array
    {
        return array_values(array_map(static fn (array $f): string => (string) $f['name'], $fields));
    }

    /** @return list<array<string, mixed>> */
    private function fields(): array
    {
        return [
            // No assignment at all: used everywhere.
            ['id' => 1, 'name' => 'subtitle', 'assigned_cat_ids' => [0]],
            // Projects only — the "Screenshot"/"GitHub" case from the report.
            ['id' => 2, 'name' => 'screenshot', 'assigned_cat_ids' => [20]],
            // Assigned to Blog, so it reaches Tutorials and PHP as well.
            ['id' => 3, 'name' => 'byline', 'assigned_cat_ids' => [10]],
            // Only Use In Subform: never on an article form.
            ['id' => 4, 'name' => 'row-label', 'assigned_cat_ids' => [-1]],
        ];
    }

    public function testAFieldAssignedToAnotherCategoryIsOutOfScope(): void
    {
        $scoped = $this->scope->forCategory($this->fields(), $this->categories(), 10);

        self::assertSame(['subtitle', 'byline'], $this->names($scoped));
    }

    public function testAFieldAssignedToAParentCategoryReachesItsDescendants(): void
    {
        // 12 (PHP) is a grandchild of 10 (Blog), which "byline" is assigned to.
        $scoped = $this->scope->forCategory($this->fields(), $this->categories(), 12);

        self::assertSame(['subtitle', 'byline'], $this->names($scoped));
    }

    public function testTheAssignedCategoryItselfMatches(): void
    {
        $scoped = $this->scope->forCategory($this->fields(), $this->categories(), 20);

        self::assertSame(['subtitle', 'screenshot'], $this->names($scoped));
    }

    public function testSubformOnlyFieldsAreNeverInScope(): void
    {
        foreach ([null, 10, 11, 12, 20] as $catId) {
            $scoped = $this->scope->forCategory($this->fields(), $this->categories(), $catId);

            self::assertNotContains('row-label', $this->names($scoped));
        }
    }

    /**
     * Joomla's own `FieldsHelper::getFields()` only filters by category when the
     * item has one; an article with no category sees every field.
     */
    public function testAnArticleWithNoCategorySeesEveryField(): void
    {
        $scoped = $this->scope->forCategory($this->fields(), $this->categories(), null);

        self::assertSame(['subtitle', 'screenshot', 'byline'], $this->names($scoped));
    }

    /**
     * The assignment is unknown when the per-field item request failed (or the
     * cache predates Grafida reading it at all). Showing the field is the only
     * safe fallback: hiding it would silently drop whatever the user typed in.
     */
    public function testAFieldWithNoKnownAssignmentStaysInScopeEverywhere(): void
    {
        $fields = [['id' => 9, 'name' => 'legacy']];

        self::assertSame(['legacy'], $this->names($this->scope->forCategory($fields, $this->categories(), 20)));
    }

    /** The site's ids arrive from JSON as strings as often as not. */
    public function testStringIdsAreHandled(): void
    {
        $fields     = [['id' => 2, 'name' => 'screenshot', 'assigned_cat_ids' => ['20']]];
        $categories = [['id' => '20', 'parent_id' => '1'], ['id' => '21', 'parent_id' => '20']];

        self::assertSame(['screenshot'], $this->names($this->scope->forCategory($fields, $categories, 21)));
        self::assertSame([], $this->names($this->scope->forCategory($fields, $categories, 10)));
    }

    public function testAnnotateExpandsTheAssignmentDownTheTreeForTheSpa(): void
    {
        $annotated = $this->scope->annotate($this->fields(), $this->categories());

        // "All categories" travels as null rather than as every id on the site.
        self::assertNull($annotated[0]['categoryIds']);

        self::assertSame([20], $annotated[1]['categoryIds']);

        /** @var list<int> $byline */
        $byline = $annotated[2]['categoryIds'];
        sort($byline);
        self::assertSame([10, 11, 12], $byline);
    }

    /** A parent_id cycle in a corrupt tree must not hang the expansion. */
    public function testACycleInTheCategoryTreeTerminates(): void
    {
        $categories = [['id' => 10, 'parent_id' => 11], ['id' => 11, 'parent_id' => 10]];
        $fields     = [['id' => 1, 'name' => 'x', 'assigned_cat_ids' => [10]]];

        $annotated = $this->scope->annotate($fields, $categories);

        /** @var list<int> $ids */
        $ids = $annotated[0]['categoryIds'];
        sort($ids);
        self::assertSame([10, 11], $ids);
    }
}
