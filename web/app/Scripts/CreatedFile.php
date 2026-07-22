<?php

declare(strict_types=1);

namespace App\Scripts;

/**
 * A CSV a script writes, described for a non-developer before they ever run it —
 * "columns created: ..." — so what a read or write actually produces is visible up
 * front, not something you only discover by opening the file afterward. Columns are
 * transcribed from the script's own fputcsv()/csv.writer() header call, not guessed;
 * see each config entry's file for the exact line grounding it.
 */
final class CreatedFile
{
    /** @param list<string> $columns */
    public function __construct(
        public readonly string $file,
        public readonly array $columns,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $file = self::requireString($raw, 'file');

        $columns = $raw['columns'] ?? null;
        if (! is_array($columns) || $columns === []) {
            throw new InvalidScriptDefinitionException("created file '{$file}' missing non-empty 'columns'");
        }

        return new self(
            file: $file,
            columns: array_values(array_map('strval', $columns)),
        );
    }

    private static function requireString(array $raw, string $key): string
    {
        if (! isset($raw[$key]) || ! is_string($raw[$key]) || $raw[$key] === '') {
            throw new InvalidScriptDefinitionException("created file is missing required field '{$key}'");
        }

        return $raw[$key];
    }
}
