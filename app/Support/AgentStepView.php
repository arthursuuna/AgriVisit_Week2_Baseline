<?php

namespace App\Support;

use App\Models\AgentRunRecord;
use App\Services\Agent\AgentStep;
use App\Services\Agent\StopReason;
use App\Services\AgentTask\AgentRunner;
use App\Services\Tools\ToolResult;

/**
 * The steps of a saved run, shaped for the run page.
 *
 * Read-only: built from the record's steps and messages. Where a step
 * succeeded and the tool returned a count, the count comes from the tool
 * result the model was sent, matched to the step by tool name and arguments
 * (unique within a run: a repeated call is stopped, not invoked).
 */
class AgentStepView
{
    public const OK       = 'ok';
    public const HELD     = 'held';
    public const DECLINED = 'declined';
    public const FAILED   = 'failed';

    /** What a tool's count is a count of; a tool not listed says "results". */
    private const COUNT_NOUNS = [
        'search_guidance'     => ['passage', 'passages'],
        'recall_farm_history' => ['past decision', 'past decisions'],
    ];

    /** @return list<array<string, mixed>> */
    public static function forRun(AgentRunRecord $run): array
    {
        $results = self::resultsByCall($run->messages ?? []);
        $steps   = $run->steps ?? [];
        $last    = array_key_last($steps);

        return array_map(function (array $step, int $i) use ($results, $run, $last) {
            $outcome = (string) $step['outcome'];
            $kind    = match ($outcome) {
                AgentStep::OK                  => self::OK,
                ToolResult::APPROVAL_REQUIRED  => self::HELD,
                StopReason::REJECTED           => self::DECLINED,
                default                        => self::FAILED,
            };

            $label = $outcome;

            if ($kind === self::OK && $step['tool'] !== null) {
                $data  = $results[self::key($step['tool'], $step['arguments'] ?? [])]['data'] ?? [];
                $count = $data['count'] ?? null;

                if (is_int($count)) {
                    [$one, $many] = self::COUNT_NOUNS[$step['tool']] ?? ['result', 'results'];
                    $label .= " — {$count} " . ($count === 1 ? $one : $many);
                }

                // Week 6: which memories reached the agent, so the officer can
                // see every one it was shown, cited or not.
                if ($refs = array_column($data['entries'] ?? [], 'memory_ref')) {
                    $label .= ' (' . implode(', ', $refs) . ')';
                }
            }

            return [
                'iteration'  => $step['iteration'],
                // The model's closing answer: its text is the run summary, shown
                // once, in the Finished block, not again in the step.
                'answer'     => $i === $last && $step['tool'] === null && $run->stop_reason === StopReason::GOAL_REACHED,
                'officer'    => str_starts_with((string) $step['plan'], 'Officer '),
                'tool'       => $step['tool'],
                'outcome'    => $outcome,
                'label'      => $label,
                'kind'       => $kind,
                'mark'       => match ($kind) {
                    self::HELD     => 'Held',
                    self::DECLINED => str_starts_with((string) ($step['message'] ?? ''), AgentRunner::EXPIRED_PREFIX)
                        ? 'Expired' : 'Declined',
                    self::FAILED   => 'Failed',
                    default        => null,
                },
                'durationMs' => (float) ($step['duration_ms'] ?? 0),
                'plan'       => (string) $step['plan'],
                'arguments'  => array_map(self::value(...), $step['arguments'] ?? []),
                'rawArguments' => $step['arguments'] ?? [],
                'message'    => $step['message'] ?? null,
            ];
        }, $steps, array_keys($steps));
    }

    /**
     * Every memory entry recall_farm_history returned in this run, as the
     * model received it (not re-read from farm_memories, so an entry deleted
     * since still shows what the run was told). For the approval panel.
     *
     * @return list<array>
     */
    public static function recalledMemories(AgentRunRecord $run): array
    {
        $entries = [];

        foreach (self::resultsByCall($run->messages ?? []) as $key => $result) {
            if (str_starts_with($key, 'recall_farm_history ')) {
                foreach ($result['data']['entries'] ?? [] as $entry) {
                    $entries[$entry['memory_ref']] = $entry;
                }
            }
        }

        return array_values($entries);
    }

    /** A parameter value as one line of text. */
    private static function value(mixed $value): string
    {
        return match (true) {
            is_bool($value)   => $value ? 'true' : 'false',
            $value === null   => 'null',
            is_scalar($value) => (string) $value,
            default           => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    /** Decoded tool results keyed by the call that produced them. */
    private static function resultsByCall(array $messages): array
    {
        $calls   = [];
        $results = [];

        foreach ($messages as $message) {
            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    $calls[$block['id']] = self::key($block['name'], (array) ($block['input'] ?? []));
                } elseif (($block['type'] ?? null) === 'tool_result' && isset($calls[$block['tool_use_id']])) {
                    $decoded = is_string($block['content'] ?? null) ? json_decode($block['content'], true) : null;

                    if (is_array($decoded)) {
                        $results[$calls[$block['tool_use_id']]] = $decoded;
                    }
                }
            }
        }

        return $results;
    }

    private static function key(string $tool, array $arguments): string
    {
        ksort($arguments);

        return $tool . ' ' . json_encode($arguments);
    }
}
