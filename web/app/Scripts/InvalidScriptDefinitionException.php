<?php

declare(strict_types=1);

namespace App\Scripts;

/**
 * Thrown when a config/scripts/*.php entry can't be parsed into a valid
 * ScriptDefinition/ParamDefinition. Always thrown with enough context (which
 * script slug, which field) to fix the config file without a debugger.
 */
final class InvalidScriptDefinitionException extends \RuntimeException
{
    public function withSlugContext(string $slug): self
    {
        return new self("script '{$slug}': {$this->getMessage()}", previous: $this);
    }
}
