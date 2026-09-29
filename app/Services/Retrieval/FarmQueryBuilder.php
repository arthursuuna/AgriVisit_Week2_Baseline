<?php

namespace App\Services\Retrieval;

/**
 * Turns a farm profile into retrieval queries.
 *
 * The profile itself is not a query: farm ID, area and water source are
 * irrelevant to retrieval and dilute the signal. A single blended query also
 * returns passages vaguely about everything, so each outstanding issue becomes
 * its own query, with the crop names appended to steer it to the right manual.
 */
class FarmQueryBuilder
{
    /**
     * One query per outstanding issue, one for the officer notes if any, and
     * one for the crops alone. Duplicates are removed.
     *
     * @param  array<string, mixed>  $farm
     * @return list<string>
     */
    public function build(array $farm, string $notes = ''): array
    {
        $crops   = implode(', ', $this->crops($farm));
        $queries = [];

        foreach ($farm['outstanding_issues'] ?? [] as $issue) {
            if (is_string($issue) && trim($issue) !== '') {
                $queries[] = $this->withCrops(trim($issue), $crops);
            }
        }

        if (trim($notes) !== '') {
            $queries[] = $this->withCrops(trim($notes), $crops);
        }

        if ($crops !== '') {
            $queries[] = $crops;
        }

        return array_values(array_unique($queries));
    }

    /**
     * @param  array<string, mixed>  $farm
     * @return list<string>
     */
    public function crops(array $farm): array
    {
        $names = [];

        foreach ($farm['crops'] ?? [] as $crop) {
            $name = is_array($crop) ? ($crop['crop'] ?? null) : $crop;

            if (is_string($name) && trim($name) !== '') {
                $names[] = mb_strtolower(trim($name));
            }
        }

        return array_values(array_unique($names));
    }

    private function withCrops(string $text, string $crops): string
    {
        return $crops === '' ? $text : "{$text} {$crops}";
    }
}
