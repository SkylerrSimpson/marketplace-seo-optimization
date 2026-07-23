<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

/**
 * Read the description / maxLength off an Amazon product-type schema property.
 *
 * Amazon nests the real constraints under items.properties.value, so both
 * lookups unwrap that node first (mirrors schemaValueNode/schemaMaxLength in
 * draft_listings.php). Pure / read-only.
 */
final class SchemaReader
{
    /** The nested {value} constraint node, or the property itself when flat. */
    public static function valueNode(array $prop): array
    {
        $node = $prop['items']['properties']['value'] ?? null;
        return is_array($node) ? $node : $prop;
    }

    public static function description(array $prop): string
    {
        return trim((string) ($prop['description'] ?? self::valueNode($prop)['description'] ?? ''));
    }

    public static function maxLength(array $prop): ?int
    {
        $max = self::valueNode($prop)['maxLength'] ?? $prop['maxLength'] ?? null;
        return is_int($max) ? $max : (is_numeric($max) ? (int) $max : null);
    }
}
