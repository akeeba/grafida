<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Field;

/**
 * Works out which custom fields Joomla actually uses for a given article
 * category — the rule `FieldsHelper::getFields()` applies when it builds the
 * article edit form, reimplemented here because the API cannot be asked.
 *
 * Joomla's rule, from `com_fields`' `FieldsModel::getListQuery()`:
 *
 * - A field with **no** row in `#__fields_categories` applies to every category.
 *   The item API reports that as `assigned_cat_ids = [0]`.
 * - A field assigned to categories applies to those categories **and to all
 *   their descendants** — `getFields()` passes the article's category and the
 *   model walks the tree *up*, matching a field pinned to any ancestor.
 * - `assigned_cat_ids = [-1]` is "Only Use In Subform": never shown on an
 *   article form at all.
 * - An article with no category at all is filtered by nothing, so it sees
 *   every field.
 *
 * ⚠️ **The assignment is not in the fields *list* endpoint.** `com_fields`'
 * `JsonapiView::$fieldsToRenderList` omits `assigned_cat_ids` (only
 * `$fieldsToRenderItem` has it), and its API controller forwards no `filter[…]`
 * to the model — `ApiController::displayList()` builds the model with
 * `ignore_request`, so `populateState()` never runs and there is no
 * `filter[assigned_cat_ids]` to pass. {@see \Grafida\Reference\ReferenceService}
 * therefore fetches each field's *item* endpoint once per cache refresh to
 * learn it; a field whose assignment could not be read falls back to `[0]`,
 * i.e. it keeps showing everywhere.
 *
 * The expansion runs **server-side and downwards** — {@see annotate()} gives each
 * field the complete list of category ids it applies to — precisely so the SPA
 * needs nothing but an `includes()` test and this tree walk has exactly one
 * implementation.
 */
final class FieldCategoryScope
{
    /**
     * The pseudo-category Joomla stores for "Only Use In Subform" fields, which
     * never appear on an article form.
     */
    private const SUBFORM_ONLY = -1;

    /**
     * Annotates field definitions with `categoryIds`: `null` when the field
     * applies to every category, otherwise the expanded list of category ids
     * (the assigned categories plus all their descendants) it applies to.
     * Subform-only fields are dropped outright.
     *
     * @param list<array<string, mixed>> $fields     Field definitions, as cached.
     * @param list<array<string, mixed>> $categories The site's cached categories.
     *
     * @return list<array<string, mixed>>
     */
    public function annotate(array $fields, array $categories): array
    {
        $children = self::childrenByParent($categories);
        $out      = [];

        foreach ($fields as $field) {
            $assigned = self::assignedIds($field);

            if (in_array(self::SUBFORM_ONLY, $assigned, true)) {
                continue;
            }

            $field['categoryIds'] = $assigned === [] || in_array(0, $assigned, true)
                ? null
                : self::expand($assigned, $children);

            $out[] = $field;
        }

        return $out;
    }

    /**
     * The subset of `$fields` Joomla would show on an article in `$catId`.
     *
     * A null category (Grafida's "— None —") matches Joomla: with no category to
     * filter by, every field is in scope.
     *
     * @param list<array<string, mixed>> $fields
     * @param list<array<string, mixed>> $categories
     *
     * @return list<array<string, mixed>>
     */
    public function forCategory(array $fields, array $categories, ?int $catId): array
    {
        $annotated = $this->annotate($fields, $categories);

        if ($catId === null || $catId <= 0) {
            return $annotated;
        }

        return array_values(array_filter(
            $annotated,
            static function (array $field) use ($catId): bool {
                $ids = $field['categoryIds'] ?? null;

                return $ids === null || (is_array($ids) && in_array($catId, $ids, true));
            }
        ));
    }

    /**
     * A field's raw category assignment, as integers. An absent or unreadable
     * value means "every category" (`[0]`) — the behaviour Grafida had before
     * it read the assignment at all, and the only safe fallback: hiding a field
     * we failed to place would silently drop the value the user typed into it.
     *
     * @param array<string, mixed> $field
     *
     * @return list<int>
     */
    private static function assignedIds(array $field): array
    {
        $raw = $field['assigned_cat_ids'] ?? null;

        if (!is_array($raw) || $raw === []) {
            return [0];
        }

        $ids = [];

        foreach ($raw as $value) {
            if (is_int($value) || (is_string($value) && is_numeric($value))) {
                $ids[] = (int) $value;
            }
        }

        return $ids === [] ? [0] : $ids;
    }

    /**
     * @param list<array<string, mixed>> $categories
     *
     * @return array<int, list<int>> parent id => its direct children's ids
     */
    private static function childrenByParent(array $categories): array
    {
        $children = [];

        foreach ($categories as $category) {
            $id     = $category['id'] ?? null;
            $parent = $category['parent_id'] ?? null;

            if (!is_numeric($id) || !is_numeric($parent)) {
                continue;
            }

            $children[(int) $parent][] = (int) $id;
        }

        return $children;
    }

    /**
     * The assigned ids plus every descendant of each, breadth-first. Guards
     * against a cycle in a corrupt tree by never visiting an id twice.
     *
     * @param list<int>              $assigned
     * @param array<int, list<int>>  $children
     *
     * @return list<int>
     */
    private static function expand(array $assigned, array $children): array
    {
        $seen  = [];
        $queue = $assigned;

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return array_map(intval(...), array_keys($seen));
    }
}
