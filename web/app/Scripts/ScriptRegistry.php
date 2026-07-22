<?php

declare(strict_types=1);

namespace App\Scripts;

use Illuminate\Support\Collection;

final class ScriptRegistry
{
    /** @var array<string, ScriptDefinition> slug => definition */
    private readonly array $definitions;

    public function __construct(?string $configDir = null)
    {
        $this->definitions = $this->loadAll($configDir ?? config_path('scripts'));
    }

    /** @return array<string, ScriptDefinition> */
    private function loadAll(string $configDir): array
    {
        $definitions = [];

        // Glob the directory rather than a hardcoded marketplace list: adding
        // walmart.php later needs zero changes here, only a new config file —
        // the same "config is data" idea one level up from ScriptDefinition itself.
        foreach (glob($configDir.'/*.php') ?: [] as $path) {
            $marketplace = basename($path, '.php');
            $rawList = require $path;

            if (! is_array($rawList)) {
                throw new InvalidScriptDefinitionException(
                    "config/scripts/{$marketplace}.php must return an array"
                );
            }

            foreach ($rawList as $raw) {
                $definition = ScriptDefinition::fromArray($marketplace, $raw);

                if (isset($definitions[$definition->slug])) {
                    throw new InvalidScriptDefinitionException(
                        "duplicate script slug '{$definition->slug}' (registered more than once)"
                    );
                }

                $definitions[$definition->slug] = $definition;
            }
        }

        return $definitions;
    }

    /** @return Collection<string, ScriptDefinition> */
    public function all(): Collection
    {
        return collect($this->definitions);
    }

    /** @return Collection<string, ScriptDefinition> */
    public function forMarketplace(string $marketplace): Collection
    {
        // An unknown/empty marketplace (Walmart, Amazon today) is a valid state,
        // not an error — the nav still needs to render an empty section for it.
        return $this->all()->filter(
            static fn (ScriptDefinition $d): bool => $d->marketplace === $marketplace
        );
    }

    public function find(string $slug): ScriptDefinition
    {
        return $this->definitions[$slug]
            ?? throw new \OutOfBoundsException("no script registered with slug '{$slug}'");
    }

    /**
     * Same lookup as find(), but null instead of throwing for an unknown
     * slug — for list views (dashboard, runs index) resolving a historical
     * ScriptRun's title: a since-removed/renamed script shouldn't 500 an
     * otherwise-fine list page over one stale row.
     */
    public function findOrNull(string $slug): ?ScriptDefinition
    {
        return $this->definitions[$slug] ?? null;
    }

    /** @return Collection<string, ScriptDefinition> */
    public function featured(?string $marketplace = null): Collection
    {
        $scripts = $marketplace === null ? $this->all() : $this->forMarketplace($marketplace);

        return $scripts->filter(static fn (ScriptDefinition $d): bool => $d->featured);
    }

    /**
     * The script (if any) tagged `connection_check: true` for this
     * marketplace — e.g. ebay.check-connection. Null for a marketplace with
     * no such script yet (Shopify today), so callers can render nothing
     * rather than special-casing marketplace names.
     */
    public function connectionCheckFor(string $marketplace): ?ScriptDefinition
    {
        return $this->forMarketplace($marketplace)
            ->first(static fn (ScriptDefinition $d): bool => $d->connectionCheck);
    }
}
