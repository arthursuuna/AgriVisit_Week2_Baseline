<?php

namespace App\Services\AgentTask;

use App\Models\AgentRunRecord;

/**
 * The citation rule, checked (Week 6 Phase 2): every reference the agent
 * writes must be one a tool returned to it in the same run. A memory_ref must
 * have come from recall_farm_history; a chunk_ref from search_guidance.
 *
 * This is the one direction that can be checked. Nothing can show that the
 * agent did NOT let a recalled memory or passage shape something it failed to
 * cite: the guarantee is that a cited reference is real, not that every
 * influence is cited.
 *
 * Reads the conversation from agent_runs, so a resumed run is checked across
 * all its segments. Evidence only: nothing here changes what a run does.
 */
class CitationCheck
{
    /** reference pattern => the tool whose results may supply it, and where */
    private const KINDS = [
        'memory_ref' => ['pattern' => '/\bM-\d{4,}\b/', 'tool' => 'recall_farm_history', 'list' => 'entries'],
        'chunk_ref'  => ['pattern' => '/\bDOC-\d{3}-C\d{3}\b/', 'tool' => 'search_guidance', 'list' => 'results'],
    ];

    /**
     * @return array<string, array{cited: list<string>, returned: list<string>, unsupported: list<string>}>
     */
    public function check(AgentRunRecord $run): array
    {
        $text     = $this->agentText($run->messages ?? []);
        $returned = $this->returned($run->messages ?? []);
        $report   = [];

        foreach (self::KINDS as $kind => $spec) {
            preg_match_all($spec['pattern'], $text, $m);
            $cited = array_values(array_unique($m[0]));

            $report[$kind] = [
                'cited'       => $cited,
                'returned'    => $returned[$kind],
                'unsupported' => array_values(array_diff($cited, $returned[$kind])),
            ];
        }

        return $report;
    }

    public function passes(array $report): bool
    {
        return array_sum(array_map(fn (array $r) => count($r['unsupported']), $report)) === 0;
    }

    /**
     * Everything the agent wrote: every text and thinking block of every
     * assistant turn, the final answer included. Thinking counts because the
     * officer reads it: when a turn has no text, ModelReply::plan() shows the
     * thinking as the step's plan (Phase 2 live run 2 cited M-0001 there).
     */
    private function agentText(array $messages): string
    {
        $text = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'assistant' && is_array($message['content'])) {
                foreach ($message['content'] as $block) {
                    $text[] = match ($block['type'] ?? null) {
                        'text'     => $block['text'],
                        'thinking' => (string) ($block['thinking'] ?? ''),
                        default    => '',
                    };
                }
            }
        }

        return implode("\n", $text);
    }

    /** References returned by each tool, read from the tool results under the tool_use ids that called it. */
    private function returned(array $messages): array
    {
        $toolOf   = [];
        $returned = array_fill_keys(array_keys(self::KINDS), []);

        foreach ($messages as $message) {
            if (! is_array($message['content'])) {
                continue;
            }

            foreach ($message['content'] as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    $toolOf[$block['id']] = $block['name'];
                }

                if (($block['type'] ?? null) !== 'tool_result') {
                    continue;
                }

                $data = json_decode((string) $block['content'], true)['data'] ?? [];

                foreach (self::KINDS as $kind => $spec) {
                    if (($toolOf[$block['tool_use_id']] ?? null) === $spec['tool']) {
                        array_push($returned[$kind], ...array_column($data[$spec['list']] ?? [], $kind));
                    }
                }
            }
        }

        return array_map(fn (array $refs) => array_values(array_unique($refs)), $returned);
    }
}
