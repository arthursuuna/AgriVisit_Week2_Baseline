<?php

namespace App\Console\Commands;

use App\Services\Checklist\ChecklistDrafter;
use App\Services\Checklist\DraftResult;
use App\Support\FarmProfileRepository;
use Illuminate\Console\Command;

/**
 * Runs the 10-case prompt evaluation set against the configured prompt version
 * and writes a markdown results table.
 *
 *   php artisan agrivisit:evaluate
 *   php artisan agrivisit:evaluate --prompt=v1.0
 *
 * Running the same set across versions is what makes a prompt change evidence
 * rather than an opinion (Lecture 3, slide 30).
 */
class RunPromptEvaluation extends Command
{
    protected $signature = 'agrivisit:evaluate
                            {--prompt= : Prompt version to test, e.g. v1.0}
                            {--out= : Output markdown path}';

    protected $description = 'Run the prompt evaluation set and record expected vs actual behaviour';

    public function handle(FarmProfileRepository $farms): int
    {
        $version = $this->option('prompt') ?: config('agrivisit.prompt_version');
        $out = $this->option('out') ?: base_path("evaluation/prompt-eval-{$version}.md");

        $casesPath = base_path('database/evaluation/prompt_cases.json');
        $cases = json_decode(file_get_contents($casesPath), true);

        if (! is_array($cases)) {
            $this->error('Could not read evaluation cases.');

            return self::FAILURE;
        }

        $drafter = app()->makeWith(ChecklistDrafter::class, ['promptVersion' => $version]);

        $rows = [];
        $passed = 0;

        foreach ($cases as $case) {
            $this->line("Running {$case['id']} — {$case['intent']}");

            $farm = $farms->find($case['farm_id']);

            if ($farm === null) {
                $result = DraftResult::failed('Farm profile not found.', $version, 'n/a');
            } else {
                $result = $drafter->draft($farm, $case['notes'] ?? '');
            }

            $actual = $this->describe($result);
            $ok = $this->evaluate($case['checks'] ?? [], $result);
            $passed += $ok ? 1 : 0;

            $rows[] = [
                'id'       => $case['id'],
                'intent'   => $case['intent'],
                'expected' => $case['expected'],
                'actual'   => $actual,
                'verdict'  => $ok ? 'Pass' : 'Review',
                'trace'    => $result->traceId,
            ];
        }

        $this->writeMarkdown($out, $version, $rows, $passed, count($cases));

        $this->newLine();
        $this->info(sprintf('%d/%d automated checks passed. Results: %s', $passed, count($cases), $out));
        $this->comment('Checks marked "Review" need a human read of the trace before you record a verdict.');

        return self::SUCCESS;
    }

    private function describe(DraftResult $result): string
    {
        return match ($result->status) {
            DraftResult::STATUS_DRAFTED => sprintf(
                'Drafted %d items; %d ms; %d/%d tokens.',
                count($result->items),
                $result->meta?->latencyMs ?? 0,
                $result->meta?->inputTokens ?? 0,
                $result->meta?->outputTokens ?? 0
            ),
            DraftResult::STATUS_REFUSED => sprintf('Refused (%s).', $result->refusalCategory),
            default                     => 'Failed: ' . $result->message,
        };
    }

    /** Mechanical checks only. Judgement checks are flagged for human review. */
    private function evaluate(array $checks, DraftResult $result): bool
    {
        foreach ($checks as $check) {
            [$kind, $arg] = array_pad(explode(':', $check, 2), 2, null);

            $ok = match ($kind) {
                'status'   => $result->status === $arg,
                'category' => $result->refusalCategory === $arg,
                'item_count_in_range' => $result->isDrafted()
                    && count($result->items) >= config('agrivisit.checklist.min_items')
                    && count($result->items) <= config('agrivisit.checklist.max_items'),
                'all_ungrounded' => $result->isDrafted() && collect($result->items)
                    ->every(fn ($i) => ($i['grounding'] ?? null) === 'ungrounded'),
                'valid_json' => $result->isDrafted(),
                default      => true, // judgement check: left for human review
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function writeMarkdown(string $path, string $version, array $rows, int $passed, int $total): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $llm = config('agrivisit.llm');

        $md = "# Prompt Evaluation — Checklist Drafting {$version}\n\n";
        $md .= '| Setting | Value |' . "\n" . '|---|---|' . "\n";
        $md .= "| Prompt version | `{$version}` |\n";
        $md .= "| Provider | {$llm['provider']} |\n";
        $md .= "| Model | `{$llm['model']}` |\n";
        $md .= "| Temperature | {$llm['temperature']} |\n";
        $md .= '| Max tokens | ' . $llm['max_tokens'] . " |\n";
        $md .= '| Run at | ' . date('c') . " |\n";
        $md .= "| Automated checks passed | {$passed}/{$total} |\n\n";

        $md .= "| # | Intent | Expected behaviour | Actual behaviour | Verdict | Trace |\n";
        $md .= "|---|---|---|---|---|---|\n";

        foreach ($rows as $r) {
            $md .= sprintf(
                "| %s | %s | %s | %s | %s | `%s` |\n",
                $r['id'],
                str_replace('|', '\\|', $r['intent']),
                str_replace('|', '\\|', $r['expected']),
                str_replace('|', '\\|', $r['actual']),
                $r['verdict'],
                substr($r['trace'], 0, 8)
            );
        }

        $md .= "\nVerdict `Review` means an automated check could not decide the case. "
            . "Open the trace in `storage/app/traces/` and record the judgement manually.\n";

        file_put_contents($path, $md);
    }
}
