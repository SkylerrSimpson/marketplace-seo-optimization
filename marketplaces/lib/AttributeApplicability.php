<?php

declare(strict_types=1);

/**
 * Per-product-type attribute applicability cache.
 *
 * Amazon product-type schemas carry ~130-150 optional properties, most of which
 * are structurally irrelevant to any given product type: a DRINKING_CUP will
 * never have an `athlete`, `hard_disk`, `lithium_battery`, or `digital_storage_
 * capacity`. Under --include-recommended the drafter would otherwise pay a prose
 * model to write `null` for every one of them — and prose output tokens are the
 * single biggest line item in the per-SKU cost.
 *
 * This class caches, per product type, the set of tail attributes that never
 * plausibly apply, so those attributes are dropped before the AI authoring pass.
 * The verdict is produced ONCE per product type by a cheap triage model call
 * (made by the caller — this class is pure I/O + logic), then persisted to
 * data/schemas/applicability/{PRODUCT_TYPE}.json and reused for every future SKU
 * of that type. The triage cost amortizes across the whole account to nothing.
 *
 * Safety bias: the triage is framed as "which attributes NEVER apply?" and only
 * structurally-impossible attributes are dropped. Anything not explicitly flagged
 * is kept, and required / high-value attributes are NEVER dropped regardless of
 * the verdict — a wrongly-excluded attribute silently never gets authored, so we
 * err toward inclusion.
 *
 * Pure. No API calls (the caller injects the triage result via save()); only the
 * cache is read/written here.
 */
final class AttributeApplicability
{
    /** In-process memo so repeated SKUs of one product type hit disk once. */
    private static array $memo = [];

    /**
     * Cache directory — beside the schemas, shared across all seller accounts
     * because applicability is a property of the product type, not the seller.
     */
    public static function cacheDir(): string
    {
        return AMAZON_SCHEMAS . '/applicability';
    }

    public static function cachePath(string $productType): string
    {
        return self::cacheDir() . '/' . $productType . '.json';
    }

    /**
     * Fingerprint of the product-type schema. When Amazon revises the schema the
     * hash changes and the cached verdict is treated as stale (rebuilt).
     */
    public static function schemaHash(string $productType, string $schemasDir): string
    {
        $file = $schemasDir . '/' . $productType . '.json';
        return is_file($file) ? hash('crc32b', (string) file_get_contents($file)) : '';
    }

    /**
     * The cached NOT-applicable set (lowercased attribute name => true) for a
     * product type, or null when there is no fresh cache (missing, unreadable, or
     * built against a different schema version). Null means "no verdict — author
     * the full tail".
     *
     * @return array<string, true>|null
     */
    public static function loadNotApplicable(string $productType, string $schemasDir): ?array
    {
        if (array_key_exists($productType, self::$memo)) {
            return self::$memo[$productType];
        }

        $path = self::cachePath($productType);
        if (!is_file($path)) {
            return self::$memo[$productType] = null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)
            || ($data['schema_hash'] ?? null) !== self::schemaHash($productType, $schemasDir)) {
            return self::$memo[$productType] = null; // stale or malformed
        }

        $set = [];
        foreach ($data['not_applicable'] ?? [] as $name) {
            $set[strtolower((string) $name)] = true;
        }
        return self::$memo[$productType] = $set;
    }

    /**
     * True when a fresh verdict exists on disk for this product type.
     */
    public static function isCached(string $productType, string $schemasDir): bool
    {
        return self::loadNotApplicable($productType, $schemasDir) !== null;
    }

    /**
     * Attribute names worth triaging: every schema property EXCEPT the force-keep
     * set (required / high-value / compliance). No point asking whether an
     * attribute we author regardless applies.
     *
     * @param array<string, mixed> $schemaProps schema `properties` node
     * @param array<string>        $forceKeep   lowercased names never triaged/dropped
     * @return list<string>
     */
    public static function triageCandidates(array $schemaProps, array $forceKeep): array
    {
        $keep = array_flip($forceKeep);
        $out  = [];
        foreach (array_keys($schemaProps) as $name) {
            if (isset($keep[strtolower($name)])) {
                continue;
            }
            $out[] = $name;
        }
        return $out;
    }

    /**
     * Build the triage prompt: given the product type and candidate attributes
     * (name + short description), ask the model which ones are structurally
     * impossible for the product type. Deliberately one-directional — we ask for
     * the exclusions, not the inclusions, so the model's omissions default to
     * "keep".
     *
     * @param list<string>         $names       candidate attribute names
     * @param array<string, mixed> $schemaProps schema `properties` node
     */
    public static function buildTriagePrompt(string $productType, array $names, array $schemaProps): string
    {
        $lines = [];
        foreach ($names as $name) {
            $prop = $schemaProps[$name] ?? [];
            $desc = $prop['description']
                ?? ($prop['items']['properties']['value']['description'] ?? '');
            $desc = trim((string) $desc);
            if ($desc !== '') {
                $desc = ': ' . mb_substr($desc, 0, 90);
            }
            $lines[] = '- ' . $name . $desc;
        }
        $attrBlock = implode("\n", $lines);

        return <<<PROMPT
You are an Amazon catalog taxonomy expert. Below is the product type "{$productType}"
and a list of optional listing attributes from its schema.

Identify ONLY the attributes that are STRUCTURALLY IMPOSSIBLE for this product type —
attributes that could never sensibly apply to ANY product of this type (for example,
"hard_disk", "athlete", or "lithium_battery" on a drinking cup). Be conservative: if an
attribute could plausibly apply to SOME product of this type, do NOT list it. When in
doubt, leave it out of your answer.

=== PRODUCT TYPE ===
{$productType}

=== CANDIDATE ATTRIBUTES ===
{$attrBlock}

=== INSTRUCTIONS ===
- Return ONLY a valid JSON object: {"not_applicable": ["attr_a", "attr_b", ...]}.
- List attribute names verbatim from the candidates above.
- Include an attribute only if it is clearly, structurally inapplicable to the product type.
- No markdown fences, no explanation — just the JSON object.
PROMPT;
    }

    /**
     * Parse a triage response into the not-applicable name list, scoped to the
     * candidates actually asked about (drops any name the model invented). Names
     * are returned verbatim from the candidate list for readability.
     *
     * @param list<string> $candidates the names presented to the model
     * @return list<string>
     */
    public static function parseTriage(string $raw, array $candidates): array
    {
        $raw = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $raw = trim((string) preg_replace('/\s*```$/m', '', $raw ?? ''));

        $decoded = json_decode($raw, true);
        $listed  = is_array($decoded) ? ($decoded['not_applicable'] ?? null) : null;
        if (!is_array($listed)) {
            return []; // unparseable → drop nothing (author full tail this run)
        }

        $valid = [];
        foreach ($candidates as $c) {
            $valid[strtolower($c)] = $c;
        }

        $out = [];
        foreach ($listed as $name) {
            $key = strtolower(trim((string) $name));
            if (isset($valid[$key]) && !in_array($valid[$key], $out, true)) {
                $out[] = $valid[$key];
            }
        }
        return $out;
    }

    /**
     * Persist a verdict. `applicable` is stored alongside `not_applicable` purely
     * for human review; the runtime filter only consults `not_applicable`.
     *
     * @param list<string> $candidates    every name that was triaged
     * @param list<string> $notApplicable the subset flagged structurally impossible
     */
    public static function save(
        string $productType,
        string $schemasDir,
        array  $candidates,
        array  $notApplicable,
        string $model
    ): void {
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $naSet      = array_flip(array_map('strtolower', $notApplicable));
        $applicable = array_values(array_filter(
            $candidates,
            fn($c) => !isset($naSet[strtolower($c)]),
        ));

        $payload = [
            'product_type'   => $productType,
            'schema_hash'    => self::schemaHash($productType, $schemasDir),
            'generated_at'   => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'model'          => $model,
            'candidate_count' => count($candidates),
            'not_applicable' => array_values($notApplicable),
            'applicable'     => $applicable,
        ];

        file_put_contents(
            self::cachePath($productType),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
        );

        // Refresh the in-process memo so the very next SKU sees the new verdict.
        self::$memo[$productType] = $naSet;
    }

    /**
     * Drop attributes flagged structurally inapplicable, keeping anything in the
     * force-keep set no matter what. Returns [kept, dropped] name lists.
     *
     * @param list<string>        $attrs        candidate authoring attribute names
     * @param array<string, true> $notApplicable lowercased not-applicable set (from loadNotApplicable)
     * @param array<string, true> $forceKeep    lowercased names that must survive
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function partition(array $attrs, array $notApplicable, array $forceKeep): array
    {
        $kept    = [];
        $dropped = [];
        foreach ($attrs as $attr) {
            $key = strtolower($attr);
            if (!isset($notApplicable[$key]) || isset($forceKeep[$key])) {
                $kept[] = $attr;
            } else {
                $dropped[] = $attr;
            }
        }
        return [$kept, $dropped];
    }
}
