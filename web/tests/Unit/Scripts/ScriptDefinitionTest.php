<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Scripts\InvalidScriptDefinitionException;
use App\Scripts\ScriptDefinition;
use PHPUnit\Framework\TestCase;

final class ScriptDefinitionTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function validRaw(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'fixture.example',
            'category' => 'testing',
            'title' => 'Example',
            'description' => 'An example script.',
            'type' => 'read',
            'cli_path' => 'fixture/example.php',
            'interpreter' => 'php8.2',
        ], $overrides);
    }

    public function test_output_files_defaults_to_empty_array_when_absent(): void
    {
        $definition = ScriptDefinition::fromArray('fixture', $this->validRaw());

        $this->assertSame([], $definition->outputFiles);
    }

    public function test_output_files_parses_from_config(): void
    {
        $definition = ScriptDefinition::fromArray('fixture', $this->validRaw([
            'output_files' => ['marketplaces/ebay/data/{account}/output/example.csv', 'marketplaces/ebay/data/{account}/output/other.csv'],
        ]));

        $this->assertSame(
            ['marketplaces/ebay/data/{account}/output/example.csv', 'marketplaces/ebay/data/{account}/output/other.csv'],
            $definition->outputFiles,
        );
    }

    public function test_non_array_output_files_throws(): void
    {
        $this->expectException(InvalidScriptDefinitionException::class);
        $this->expectExceptionMessageMatches("/'output_files' must be an array/");

        ScriptDefinition::fromArray('fixture', $this->validRaw(['output_files' => 'not-an-array']));
    }

    public function test_reference_files_defaults_to_empty_array_when_absent(): void
    {
        $definition = ScriptDefinition::fromArray('fixture', $this->validRaw());

        $this->assertSame([], $definition->referenceFiles);
    }

    public function test_reference_files_parses_path_and_label_pairs(): void
    {
        $definition = ScriptDefinition::fromArray('fixture', $this->validRaw([
            'reference_files' => [
                ['path' => 'marketplaces/ebay/data/{account}/output/review_sheet.csv', 'label' => 'Review Sheet'],
            ],
        ]));

        $this->assertCount(1, $definition->referenceFiles);
        $this->assertSame('marketplaces/ebay/data/{account}/output/review_sheet.csv', $definition->referenceFiles[0]->path);
        $this->assertSame('Review Sheet', $definition->referenceFiles[0]->label);
    }

    public function test_reference_file_missing_label_throws(): void
    {
        $this->expectException(InvalidScriptDefinitionException::class);
        $this->expectExceptionMessageMatches("/script 'fixture\\.example'.*'label'/");

        ScriptDefinition::fromArray('fixture', $this->validRaw([
            'reference_files' => [['path' => 'marketplaces/ebay/data/{account}/output/review_sheet.csv']],
        ]));
    }

    public function test_non_array_reference_files_throws(): void
    {
        $this->expectException(InvalidScriptDefinitionException::class);
        $this->expectExceptionMessageMatches("/'reference_files' must be an array/");

        ScriptDefinition::fromArray('fixture', $this->validRaw(['reference_files' => 'not-an-array']));
    }

    public function test_step_defaults_to_null_when_absent(): void
    {
        $definition = ScriptDefinition::fromArray('fixture', $this->validRaw());

        $this->assertNull($definition->step);
    }

    public function test_step_parses_as_int(): void
    {
        $definition = ScriptDefinition::fromArray('fixture', $this->validRaw(['step' => 3]));

        $this->assertSame(3, $definition->step);
    }

    public function test_non_int_step_throws(): void
    {
        $this->expectException(InvalidScriptDefinitionException::class);
        $this->expectExceptionMessageMatches("/'step' must be an int/");

        ScriptDefinition::fromArray('fixture', $this->validRaw(['step' => '3']));
    }

    public function test_optional_defaults_to_false_when_absent(): void
    {
        $definition = ScriptDefinition::fromArray('fixture', $this->validRaw());

        $this->assertFalse($definition->optional);
    }

    public function test_optional_parses_as_bool(): void
    {
        $definition = ScriptDefinition::fromArray('fixture', $this->validRaw(['optional' => true]));

        $this->assertTrue($definition->optional);
    }

    public function test_non_bool_optional_throws(): void
    {
        $this->expectException(InvalidScriptDefinitionException::class);
        $this->expectExceptionMessageMatches("/'optional' must be a bool/");

        ScriptDefinition::fromArray('fixture', $this->validRaw(['optional' => 'yes']));
    }

    public function test_creates_defaults_to_empty_array_when_absent(): void
    {
        $definition = ScriptDefinition::fromArray('fixture', $this->validRaw());

        $this->assertSame([], $definition->creates);
    }

    public function test_creates_parses_file_and_columns(): void
    {
        $definition = ScriptDefinition::fromArray('fixture', $this->validRaw([
            'creates' => [
                ['file' => 'example.csv', 'columns' => ['item_id', 'sku']],
            ],
        ]));

        $this->assertCount(1, $definition->creates);
        $this->assertSame('example.csv', $definition->creates[0]->file);
        $this->assertSame(['item_id', 'sku'], $definition->creates[0]->columns);
    }

    public function test_created_file_missing_columns_throws(): void
    {
        $this->expectException(InvalidScriptDefinitionException::class);
        $this->expectExceptionMessageMatches("/'columns'/");

        ScriptDefinition::fromArray('fixture', $this->validRaw([
            'creates' => [['file' => 'example.csv']],
        ]));
    }

    public function test_created_file_empty_columns_throws(): void
    {
        $this->expectException(InvalidScriptDefinitionException::class);
        $this->expectExceptionMessageMatches("/'columns'/");

        ScriptDefinition::fromArray('fixture', $this->validRaw([
            'creates' => [['file' => 'example.csv', 'columns' => []]],
        ]));
    }

    public function test_non_array_creates_throws(): void
    {
        $this->expectException(InvalidScriptDefinitionException::class);
        $this->expectExceptionMessageMatches("/'creates' must be an array/");

        ScriptDefinition::fromArray('fixture', $this->validRaw(['creates' => 'not-an-array']));
    }
}
