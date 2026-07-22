<?php

declare(strict_types=1);

namespace App\Scripts;

final class ScriptDefinition
{
    /**
     * @param  list<ParamDefinition>  $params
     * @param  list<string>  $outputFiles  repo-root-relative path templates, e.g.
     *                                     'ebay/data/{account}/output/enriched_summary.csv' — {account} is
     *                                     substituted from the run's own submitted params, never guessed.
     * @param  list<ReferenceFile>  $referenceFiles  input/lookup files this script
     *                                               reads from, shown independent of any run — see ReferenceFile.
     * @param  list<CreatedFile>  $creates  CSVs this script writes and the columns
     *                                      each one has — shown before a run so "what does this actually do" is
     *                                      answered on the page, not just in a prose description.
     * @param  ?int  $step  1-based position within this script's category's
     *                      pipeline (e.g. aspects step 1 = export listings, step 6 = apply
     *                      aspects live) — ordering only, never affects how the script runs.
     *                      Null sorts last: an unordered/standalone script.
     * @param  bool  $optional  true if this step is commonly skipped — e.g. a
     *                          QA side-branch nothing downstream requires, or a step that's
     *                          only relevant when a particular prior condition happened. Purely
     *                          a UI hint (renders a small "optional" pill); never affects how
     *                          the script runs or its position in `step` ordering.
     * @param  bool  $connectionCheck  true for the (at most one, per
     *                                 marketplace) script that proves credentials/API access work
     *                                 end-to-end — e.g. ebay.check-connection. Lets the script page's
     *                                 connection widget find the right script per marketplace via
     *                                 config, instead of the app hardcoding a slug naming convention.
     *                                 Implicit contract: `ConnectionCheckController` always invokes
     *                                 this script's CLI directly with `--all --json` (checking every
     *                                 configured account, one machine-readable JSON object of
     *                                 `{account: {ok, detail}}` on stdout) — any script registered
     *                                 with this flag must support both.
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $marketplace,
        public readonly string $category,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $usageNotes,
        public readonly ScriptType $type,
        public readonly string $cliPath,
        public readonly string $interpreter,
        public readonly bool $featured,
        public readonly array $params,
        public readonly array $outputFiles = [],
        public readonly array $referenceFiles = [],
        public readonly ?int $step = null,
        public readonly array $creates = [],
        public readonly bool $optional = false,
        public readonly bool $connectionCheck = false,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(string $marketplace, array $raw): self
    {
        $slug = self::requireString($raw, 'slug');

        try {
            $category = self::requireString($raw, 'category');
            $title = self::requireString($raw, 'title');
            $description = self::requireString($raw, 'description');
            $cliPath = self::requireString($raw, 'cli_path');
            // No default: this app runs on this machine's system-default PHP for
            // Composer itself, but every wrapped script needs 8.2+ (see PLAN.md +
            // CONTRIBUTING.md). Requiring every entry to name its interpreter
            // explicitly keeps a fact this easy to get wrong from silently
            // defaulting to the wrong runtime.
            $interpreter = self::requireString($raw, 'interpreter');

            $typeValue = self::requireString($raw, 'type');
            try {
                $type = ScriptType::from($typeValue);
            } catch (\ValueError) {
                $valid = implode(', ', array_column(ScriptType::cases(), 'value'));
                throw new InvalidScriptDefinitionException("type '{$typeValue}' is not one of: {$valid}");
            }

            $paramsRaw = $raw['params'] ?? [];
            if (! is_array($paramsRaw)) {
                throw new InvalidScriptDefinitionException("'params' must be an array");
            }
            $params = array_map(
                static fn (array $paramRaw): ParamDefinition => ParamDefinition::fromArray($paramRaw),
                $paramsRaw,
            );

            $outputFiles = $raw['output_files'] ?? [];
            if (! is_array($outputFiles)) {
                throw new InvalidScriptDefinitionException("'output_files' must be an array");
            }

            $referenceFilesRaw = $raw['reference_files'] ?? [];
            if (! is_array($referenceFilesRaw)) {
                throw new InvalidScriptDefinitionException("'reference_files' must be an array");
            }
            $referenceFiles = array_map(
                static fn (array $refRaw): ReferenceFile => ReferenceFile::fromArray($refRaw),
                $referenceFilesRaw,
            );

            $stepRaw = $raw['step'] ?? null;
            if ($stepRaw !== null && ! is_int($stepRaw)) {
                throw new InvalidScriptDefinitionException("'step' must be an int");
            }

            $optionalRaw = $raw['optional'] ?? false;
            if (! is_bool($optionalRaw)) {
                throw new InvalidScriptDefinitionException("'optional' must be a bool");
            }

            $connectionCheckRaw = $raw['connection_check'] ?? false;
            if (! is_bool($connectionCheckRaw)) {
                throw new InvalidScriptDefinitionException("'connection_check' must be a bool");
            }

            $createsRaw = $raw['creates'] ?? [];
            if (! is_array($createsRaw)) {
                throw new InvalidScriptDefinitionException("'creates' must be an array");
            }
            $creates = array_map(
                static fn (array $c): CreatedFile => CreatedFile::fromArray($c),
                $createsRaw,
            );

            return new self(
                slug: $slug,
                marketplace: $marketplace,
                category: $category,
                title: $title,
                description: $description,
                usageNotes: $raw['usage_notes'] ?? null,
                type: $type,
                cliPath: $cliPath,
                interpreter: $interpreter,
                featured: (bool) ($raw['featured'] ?? false),
                params: $params,
                outputFiles: array_values(array_map('strval', $outputFiles)),
                referenceFiles: array_values($referenceFiles),
                step: $stepRaw,
                creates: array_values($creates),
                optional: $optionalRaw,
                connectionCheck: $connectionCheckRaw,
            );
        } catch (InvalidScriptDefinitionException $e) {
            throw $e->withSlugContext($slug);
        }
    }

    private static function requireString(array $raw, string $key): string
    {
        if (! isset($raw[$key]) || ! is_string($raw[$key]) || $raw[$key] === '') {
            throw new InvalidScriptDefinitionException("missing required field '{$key}'");
        }

        return $raw[$key];
    }
}
