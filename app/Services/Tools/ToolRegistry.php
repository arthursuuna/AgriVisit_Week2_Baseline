<?php

namespace App\Services\Tools;

use LogicException;

/**
 * The allow-list. A name not registered here cannot be invoked.
 */
class ToolRegistry
{
    /** @var array<string, ToolContract> */
    private array $tools = [];

    public function register(ToolContract $tool): void
    {
        // A silent overwrite would let one tool shadow another.
        if ($this->has($tool->name())) {
            throw new LogicException("A tool named '{$tool->name()}' is already registered.");
        }

        $this->tools[$tool->name()] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?ToolContract
    {
        return $this->tools[$name] ?? null;
    }

    /** @return list<ToolContract> */
    public function all(): array
    {
        return array_values($this->tools);
    }
}
