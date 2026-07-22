<?php

declare(strict_types=1);

namespace App\Scripts;

final class ParamDefinition
{
    /** @param list<string>|null $options */
    public function __construct(
        public readonly string $name,
        public readonly string $flag,
        public readonly ParamType $type,
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly ?array $options = null,
        public readonly ?string $help = null,
    ) {
        if ($this->type === ParamType::Enum && empty($this->options)) {
            throw new InvalidScriptDefinitionException(
                "param '{$this->name}': type Enum requires a non-empty 'options' list"
            );
        }
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $name = self::requireString($raw, 'name');
        $flag = self::requireString($raw, 'flag');

        $typeValue = self::requireString($raw, 'type');
        try {
            $type = ParamType::from($typeValue);
        } catch (\ValueError) {
            $valid = implode(', ', array_column(ParamType::cases(), 'value'));
            throw new InvalidScriptDefinitionException(
                "param '{$name}': type '{$typeValue}' is not one of: {$valid}"
            );
        }

        return new self(
            name: $name,
            flag: $flag,
            type: $type,
            required: (bool) ($raw['required'] ?? false),
            default: $raw['default'] ?? null,
            options: $raw['options'] ?? null,
            help: $raw['help'] ?? null,
        );
    }

    private static function requireString(array $raw, string $key): string
    {
        if (! isset($raw[$key]) || ! is_string($raw[$key]) || $raw[$key] === '') {
            throw new InvalidScriptDefinitionException("param is missing required field '{$key}'");
        }

        return $raw[$key];
    }
}
