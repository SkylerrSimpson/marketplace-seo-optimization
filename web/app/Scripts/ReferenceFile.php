<?php

declare(strict_types=1);

namespace App\Scripts;

/**
 * An input/lookup file a script reads from — distinct from a ScriptRun's
 * outputFiles (which reflect "what did this run produce" and get copied per-run).
 * A reference file always reflects the file's live current state on disk; there's
 * no run to scope it to. Needs a label, not just a path — the whole point is
 * explaining *why* the file matters, which a bare path can't do.
 */
final class ReferenceFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $label,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            path: self::requireString($raw, 'path'),
            label: self::requireString($raw, 'label'),
        );
    }

    public function needsAccount(): bool
    {
        return str_contains($this->path, '{account}');
    }

    private static function requireString(array $raw, string $key): string
    {
        if (! isset($raw[$key]) || ! is_string($raw[$key]) || $raw[$key] === '') {
            throw new InvalidScriptDefinitionException("reference file missing required field '{$key}'");
        }

        return $raw[$key];
    }
}
