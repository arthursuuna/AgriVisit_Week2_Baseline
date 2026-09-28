<?php

namespace App\Support;

use RuntimeException;

/**
 * Reads synthetic farm profiles from a JSON file.
 *
 * Week 2 needs only enough profiles to exercise the prompt. The full 40-50
 * record set and its database backing arrive with the tool layer in Week 4.
 * All data is team-created; no real farmer information is used.
 */
class FarmProfileRepository
{
    private ?array $cache = null;

    public function __construct(private readonly string $path)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (! is_file($this->path)) {
            throw new RuntimeException("Farm profile file not found: {$this->path}");
        }

        $data = json_decode(file_get_contents($this->path), true);

        if (! is_array($data)) {
            throw new RuntimeException('Farm profile file is not valid JSON.');
        }

        return $this->cache = $data;
    }

    public function find(string $farmId): ?array
    {
        foreach ($this->all() as $farm) {
            if (($farm['farm_id'] ?? null) === $farmId) {
                return $farm;
            }
        }

        return null;
    }
}
