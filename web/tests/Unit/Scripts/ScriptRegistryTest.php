<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Scripts\InvalidScriptDefinitionException;
use App\Scripts\ParamType;
use App\Scripts\ScriptRegistry;
use App\Scripts\ScriptType;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the loader/validator against small hand-built fixture configs, never
 * against the real config/scripts/ebay.php — see RealEbayRegistryIntegrityTest for
 * that. This suite tests the mechanism and should not need to change as more real
 * scripts get registered.
 */
final class ScriptRegistryTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configDir = sys_get_temp_dir().'/dowscripts_registry_test_'.uniqid();
        mkdir($this->configDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->configDir.'/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->configDir);
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $entries */
    private function writeMarketplaceConfig(string $marketplace, array $entries): void
    {
        $export = var_export($entries, true);
        file_put_contents("{$this->configDir}/{$marketplace}.php", "<?php\nreturn {$export};\n");
    }

    /** @param array<string, mixed> $overrides */
    private function validEntry(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'fixture.example',
            'category' => 'testing',
            'title' => 'Example',
            'description' => 'An example script.',
            'type' => 'read',
            'cli_path' => 'fixture/example.php',
            'interpreter' => 'php8.2',
            'params' => [
                [
                    'name' => 'account',
                    'flag' => '--account',
                    'type' => 'enum',
                    'required' => true,
                    'options' => ['dows', 'ige'],
                ],
            ],
        ], $overrides);
    }

    public function test_loads_a_valid_entry_into_a_typed_definition(): void
    {
        $this->writeMarketplaceConfig('fixture', [$this->validEntry()]);

        $registry = new ScriptRegistry($this->configDir);
        $definition = $registry->find('fixture.example');

        $this->assertSame('fixture', $definition->marketplace);
        $this->assertSame(ScriptType::Read, $definition->type);
        $this->assertCount(1, $definition->params);
        $this->assertSame(ParamType::Enum, $definition->params[0]->type);
        $this->assertSame(['dows', 'ige'], $definition->params[0]->options);
    }

    public function test_duplicate_slug_across_files_throws(): void
    {
        $this->writeMarketplaceConfig('fixture_a', [$this->validEntry(['slug' => 'dup.slug'])]);
        $this->writeMarketplaceConfig('fixture_b', [$this->validEntry(['slug' => 'dup.slug'])]);

        $this->expectException(InvalidScriptDefinitionException::class);
        $this->expectExceptionMessageMatches('/duplicate script slug \'dup\.slug\'/');

        new ScriptRegistry($this->configDir);
    }

    public function test_missing_required_field_throws_naming_the_field_and_slug(): void
    {
        $entry = $this->validEntry();
        unset($entry['title']);
        $this->writeMarketplaceConfig('fixture', [$entry]);

        $this->expectException(InvalidScriptDefinitionException::class);
        $this->expectExceptionMessageMatches("/script 'fixture\\.example'.*'title'/");

        new ScriptRegistry($this->configDir);
    }

    public function test_enum_param_without_options_throws(): void
    {
        $entry = $this->validEntry([
            'params' => [
                ['name' => 'bad', 'flag' => '--bad', 'type' => 'enum'],
            ],
        ]);
        $this->writeMarketplaceConfig('fixture', [$entry]);

        $this->expectException(InvalidScriptDefinitionException::class);
        $this->expectExceptionMessageMatches("/type Enum requires a non-empty 'options'/");

        new ScriptRegistry($this->configDir);
    }

    public function test_unknown_marketplace_returns_empty_collection_not_an_error(): void
    {
        $this->writeMarketplaceConfig('fixture', [$this->validEntry()]);

        $registry = new ScriptRegistry($this->configDir);

        $this->assertTrue($registry->forMarketplace('walmart')->isEmpty());
    }

    public function test_find_throws_out_of_bounds_for_unknown_slug(): void
    {
        $this->writeMarketplaceConfig('fixture', [$this->validEntry()]);
        $registry = new ScriptRegistry($this->configDir);

        $this->expectException(\OutOfBoundsException::class);

        $registry->find('nope.does-not-exist');
    }

    public function test_featured_filters_correctly(): void
    {
        $this->writeMarketplaceConfig('fixture', [
            $this->validEntry(['slug' => 'fixture.featured', 'featured' => true]),
            $this->validEntry(['slug' => 'fixture.not-featured', 'featured' => false]),
        ]);

        $registry = new ScriptRegistry($this->configDir);

        $featured = $registry->featured('fixture');
        $this->assertCount(1, $featured);
        $this->assertSame('fixture.featured', $featured->first()->slug);
    }
}
