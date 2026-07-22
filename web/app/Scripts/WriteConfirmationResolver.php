<?php

declare(strict_types=1);

namespace App\Scripts;

/**
 * Figures out, from a write script's submitted params alone (never by running the
 * script), whether a --live confirmation needs the "retype this one item ID" gate
 * or the "type WRITE" bulk gate — mirrors apply_aspects.php's own
 * `count($itemIds) > 1` rule (line 85) without needing to actually resolve the
 * selection server-side. Ambiguous cases (offset/limit, or no selection at all)
 * resolve to Bulk deliberately — the stronger gate when the true count isn't
 * knowable from params alone.
 */
final class WriteConfirmationResolver
{
    /** @param array<string, mixed> $params */
    public function resolve(array $params): WriteConfirmationMode
    {
        if ($this->nonBlank($params['item'] ?? null)) {
            return WriteConfirmationMode::Single;
        }

        $items = $this->parseItems($params);
        if ($items !== null) {
            return count($items) === 1 ? WriteConfirmationMode::Single : WriteConfirmationMode::Bulk;
        }

        return WriteConfirmationMode::Bulk;
    }

    /**
     * Only meaningful when resolve() returned Single.
     *
     * @param  array<string, mixed>  $params
     */
    public function singleItemId(array $params): ?string
    {
        if ($this->nonBlank($params['item'] ?? null)) {
            return (string) $params['item'];
        }

        $items = $this->parseItems($params);

        return $items !== null && count($items) === 1 ? $items[0] : null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>|null null when 'items' wasn't a usable selection at all
     */
    private function parseItems(array $params): ?array
    {
        if (! $this->nonBlank($params['items'] ?? null)) {
            return null;
        }

        $items = array_values(array_filter(array_map('trim', explode(',', (string) $params['items']))));

        return $items === [] ? null : $items;
    }

    private function nonBlank(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
