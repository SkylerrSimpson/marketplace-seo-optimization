<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Scripts\ScriptDefinition;
use App\Scripts\ScriptRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for a real gap found 2026-07-16: a batch of scripts got
 * registered with a real CSV in output_files but no matching 'creates' entry, so
 * their show page silently skipped the "What this creates — columns created:"
 * section entirely (that section only renders when $definition->creates is
 * non-empty — see resources/views/scripts/show.blade.php). Nothing else catches
 * this: a script with zero creates entries is a perfectly valid definition on its
 * own (plenty of scripts genuinely produce no CSV, e.g. JSON-only or live-write-
 * only outputs), so ScriptDefinitionTest can't flag it — only cross-referencing
 * output_files against creates, across every real registered script, can.
 */
final class EveryCsvOutputHasCreatesEntryTest extends TestCase
{
    public function test_every_csv_in_output_files_has_a_matching_creates_entry(): void
    {
        $configDir = dirname(__DIR__, 3).'/config/scripts';
        $registry = new ScriptRegistry($configDir);

        $missing = [];
        foreach ($registry->all() as $definition) {
            /** @var ScriptDefinition $definition */
            $createdBasenames = array_map(
                static fn ($c) => str_replace('{account}', '*', $c->file),
                $definition->creates,
            );

            foreach ($definition->outputFiles as $path) {
                if (! str_ends_with($path, '.csv')) {
                    continue;
                }

                $basename = str_replace('{account}', '*', basename($path));
                if (! in_array($basename, $createdBasenames, true)) {
                    $missing[] = "{$definition->slug}: {$basename}";
                }
            }
        }

        $this->assertSame([], $missing, "These CSV outputs have no matching 'creates' entry:\n".implode("\n", $missing));
    }
}
