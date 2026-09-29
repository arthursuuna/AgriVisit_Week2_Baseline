<?php

namespace App\Console\Commands;

use App\Services\Checklist\RestrictedTopicGuard;
use App\Services\Checklist\TraceWriter;
use App\Services\Embedding\EmbeddingException;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmResponse;
use App\Services\Prompts\PromptRepository;
use App\Services\Retrieval\EvidenceSet;
use App\Services\Retrieval\Retriever;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;

/**
 * Runs the 15-question RAG evaluation.
 *
 *   php artisan agrivisit:rag-eval
 *
 * Each question is retrieved with the crop filter and relevance threshold. If
 * nothing clears the threshold the question is declined without a model call;
 * otherwise the model answers from the supplied passages alone. One trace is
 * written per question, so a verdict can be traced to what was retrieved and
 * what was cited.
 *
 * Verdicts are PASS, FAIL, REVIEW or ERROR. REVIEW marks behaviour the harness
 * cannot judge on its own; read the answer below the table and record the
 * verdict. ERROR is an infrastructure failure (quota, overload), never counted
 * as a behavioural FAIL.
 *
 * Model calls are paced (--pause) because the Gemini free tier allows five
 * requests a minute, and a 429 or 503 is retried after the delay the API gives.
 */
class RunRagEvaluation extends Command
{
    private const ANSWER_VERSION = 'v1.0';

    private const MAX_ATTEMPTS = 4;

    protected $signature = 'agrivisit:rag-eval
                            {--out= : Output path}
                            {--pause=13 : Seconds between model calls, to stay under the per-minute quota}';

    private bool $calledModel = false;

    protected $description = 'Run the 15-question retrieval-augmented answering evaluation';

    public function handle(
        Retriever $retriever,
        LlmClient $llm,
        PromptRepository $prompts,
        RestrictedTopicGuard $guard,
        TraceWriter $traces,
    ): int {
        $questions = json_decode(file_get_contents(base_path('database/evaluation/rag_questions.json')), true);
        $out       = $this->option('out') ?: base_path('evaluation/rag-eval-v2.0.md');
        $max       = (int) config('agrivisit.retrieval.max_chunks');
        $rows      = [];

        foreach ($questions as $q) {
            $this->line("  {$q['id']}: {$q['question']}");
            $rows[] = $this->evaluate($q, $retriever, $llm, $prompts, $guard, $traces, $max);
            $this->line('     → ' . end($rows)['verdict'] . ' (' . end($rows)['reason'] . ')');
        }

        $this->writeMarkdown($out, $rows);

        $counts = array_count_values(array_column($rows, 'verdict'));
        $this->newLine();
        $this->table(['PASS', 'FAIL', 'REVIEW', 'ERROR'], [[$counts['PASS'] ?? 0, $counts['FAIL'] ?? 0, $counts['REVIEW'] ?? 0, $counts['ERROR'] ?? 0]]);
        $this->info("Report written to {$out}");

        return self::SUCCESS;
    }

    private function evaluate(
        array $q,
        Retriever $retriever,
        LlmClient $llm,
        PromptRepository $prompts,
        RestrictedTopicGuard $guard,
        TraceWriter $traces,
        int $max,
    ): array {
        $traceId = (string) Str::uuid();
        $row     = [
            'id'        => $q['id'],
            'category'  => $q['category'],
            'crops'     => $q['crops'],
            'expected'  => $q['expected_source'],
            'top_score' => null,
            'supplied'  => null,
            'cited'     => null,
            'coverage'  => null,
            'answer'    => null,
            'not_covered' => null,
            'trace'     => $traceId,
        ];

        try {
            $results = $retriever->retrieveForCrops($q['question'], $q['crops'], $max);
        } catch (EmbeddingException $e) {
            return $this->finish($row, 'FAIL', 'retrieval error: ' . $e->getMessage(), $traces, $q, []);
        }

        $evidence         = new EvidenceSet($results);
        $row['top_score'] = $results === [] ? null : round(max(array_column($results, 'score')), 4);
        $suppliedDocs     = array_values(array_unique(array_map(fn ($r) => $r['chunk']->document->doc_ref, $results)));
        $row['supplied']  = $q['expected_source'] === null ? null : in_array($q['expected_source'], $suppliedDocs, true);

        if ($evidence->isEmpty()) {
            $row['coverage'] = 'declined';

            return $q['category'] === 'unanswerable'
                ? $this->finish($row, 'PASS', 'declined: nothing cleared the threshold', $traces, $q, [])
                : $this->finish($row, 'FAIL', 'declined, but the corpus should cover this', $traces, $q, []);
        }

        $system = $prompts->render('answer', self::ANSWER_VERSION, 'system');
        $user   = $prompts->render('answer', self::ANSWER_VERSION, 'user', [
            'EVIDENCE' => $evidence->render(),
            'QUESTION' => $q['question'],
        ]);

        try {
            $response = $this->callModel($llm, $system, $user);
        } catch (LlmException $e) {
            return $this->finish(
                $row,
                'ERROR',
                'model unavailable: ' . strtok($e->getMessage(), "\n"),
                $traces,
                $q,
                ['evidence' => $evidence->toTrace(), 'error' => $e->getMessage()]
            );
        }

        $trace = ['evidence' => $evidence->toTrace(), 'model' => $response->toArray(), 'raw_output' => $response->text];

        if ($guard->screenOutput($response->text) !== null) {
            $row['coverage'] = 'refused';

            return $this->finish($row, 'FAIL', 'answer contained a dose pattern', $traces, $q, $trace);
        }

        $answer = $this->parseAnswer($response->text);

        if ($answer === null) {
            return $this->finish($row, 'FAIL', 'answer was not valid JSON', $traces, $q, $trace);
        }

        $cited   = $answer['citations'];
        $invalid = array_values(array_diff($cited, $evidence->labelList()));
        $citedDocs = array_values(array_unique(array_filter(array_map(
            fn ($label) => $evidence->chunk($label)?->document->doc_ref,
            $cited
        ))));

        $row['coverage']    = $answer['coverage'];
        $row['answer']      = $answer['answer'];
        $row['not_covered'] = $answer['not_covered'];
        $row['cited']       = $q['expected_source'] === null ? null : in_array($q['expected_source'], $citedDocs, true);

        $trace += [
            'cited_labels'   => $cited,
            'invalid_labels' => $invalid,
            'cited_chunks'   => array_values(array_filter(array_map(fn ($l) => $evidence->chunk($l)?->chunk_ref, $cited))),
            'uncited_labels' => array_values(array_diff($evidence->labelList(), $cited)),
        ];

        if ($invalid !== []) {
            return $this->finish($row, 'FAIL', 'cited labels not supplied: ' . implode(', ', $invalid), $traces, $q, $trace);
        }

        [$verdict, $reason] = $this->judge($q['category'], $row, $cited);

        return $this->finish($row, $verdict, $reason, $traces, $q, $trace);
    }

    /**
     * Paces calls to the per-minute quota and retries a rate limit (429) or an
     * overloaded model (503), waiting as long as the API asks.
     *
     * @throws LlmException
     */
    private function callModel(LlmClient $llm, string $system, string $user): LlmResponse
    {
        for ($attempt = 1; ; $attempt++) {
            if ($this->calledModel) {
                sleep((int) $this->option('pause'));
            }

            $this->calledModel = true;

            try {
                return $llm->complete($system, $user);
            } catch (LlmException $e) {
                if ($attempt >= self::MAX_ATTEMPTS || preg_match('/HTTP (429|503)\b/', $e->getMessage()) !== 1) {
                    throw $e;
                }

                $wait = preg_match('/retry in (\d+)/i', $e->getMessage(), $m) === 1 ? (int) $m[1] + 2 : 30;
                $this->warn("     model busy or over quota; retrying in {$wait}s (attempt {$attempt})");
                sleep($wait);
            }
        }
    }

    /** @return array{0: string, 1: string} */
    private function judge(string $category, array $row, array $cited): array
    {
        $coverage = $row['coverage'];

        return match ($category) {
            'answerable' => match (true) {
                $coverage === 'none'  => ['FAIL', 'model said the evidence does not cover it'],
                ! $row['supplied']    => ['FAIL', 'expected source never supplied (retrieval)'],
                ! $row['cited']       => ['FAIL', 'expected source supplied but not cited (grounding)'],
                $coverage === 'full'  => ['PASS', 'expected source supplied and cited'],
                default               => ['REVIEW', "cited the expected source but reported {$coverage} coverage"],
            },
            'partial' => match (true) {
                $coverage === 'none'                                  => ['FAIL', 'answered nothing, though part is covered'],
                $coverage === 'partial' && $cited !== [] && trim((string) $row['not_covered']) !== ''
                    => $row['cited'] ? ['PASS', 'answered the covered part and named the gap']
                                     : ['REVIEW', 'named the gap but cited another source'],
                $coverage === 'full'                                  => ['REVIEW', 'claimed full coverage; check the uncovered part was not invented'],
                default                                               => ['REVIEW', 'partial answer without a stated gap'],
            },
            'unanswerable' => match (true) {
                $coverage === 'none' && $cited === [] => ['PASS', 'model said the evidence does not address it'],
                $coverage === 'full'                  => ['FAIL', 'answered in full from evidence that should not cover it'],
                default                               => ['REVIEW', "reported {$coverage} coverage with citations; check for transfer from another crop or topic"],
            },
            default => ['REVIEW', "unknown category {$category}"],
        };
    }

    /** @return array{answer: string, citations: list<string>, coverage: string, not_covered: string}|null */
    private function parseAnswer(string $raw): ?array
    {
        $json = trim($raw);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $json, $m) === 1) {
            $json = $m[1];
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['answer'] ?? null)) {
            return null;
        }

        return [
            'answer'      => $data['answer'],
            'citations'   => array_values(array_filter((array) ($data['citations'] ?? []), 'is_string')),
            'coverage'    => is_string($data['coverage'] ?? null) ? mb_strtolower(trim($data['coverage'])) : 'unknown',
            'not_covered' => is_string($data['not_covered'] ?? null) ? $data['not_covered'] : '',
        ];
    }

    private function finish(array $row, string $verdict, string $reason, TraceWriter $traces, array $q, array $trace): array
    {
        $row['verdict'] = $verdict;
        $row['reason']  = $reason;

        $traces->write($row['trace'], [
            'kind'           => 'rag-eval',
            'question_id'    => $q['id'],
            'question'       => $q['question'],
            'category'       => $q['category'],
            'crops'          => $q['crops'],
            'expected'       => $q['expected_source'],
            'answer_version' => self::ANSWER_VERSION,
            'threshold'      => (float) config('agrivisit.retrieval.threshold'),
            'verdict'        => $verdict,
            'reason'         => $reason,
            'coverage'       => $row['coverage'],
            'answer'         => $row['answer'],
            'not_covered'    => $row['not_covered'],
        ] + $trace);

        return $row;
    }

    private function writeMarkdown(string $path, array $rows): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $yesNo = fn ($v) => $v === null ? '—' : ($v ? 'yes' : 'no');
        $llm   = config('agrivisit.llm');

        $md  = "# RAG Evaluation — prompt v2.0 retrieval, answer prompt " . self::ANSWER_VERSION . "\n\n";
        $md .= sprintf(
            "Run %s · Model `%s` · Temperature %s · Threshold %.2f · Up to %d passages · Embeddings `%s`\n\n",
            date('Y-m-d H:i'),
            $llm['model'],
            $llm['temperature'],
            config('agrivisit.retrieval.threshold'),
            config('agrivisit.retrieval.max_chunks'),
            config('agrivisit.embedding.model')
        );

        $md .= "| ID | Category | Crops | Top score | Expected source | Supplied | Cited | Coverage | Verdict | Reason |\n";
        $md .= "|---|---|---|---|---|---|---|---|---|---|\n";

        foreach ($rows as $r) {
            $md .= sprintf(
                "| %s | %s | %s | %s | %s | %s | %s | %s | **%s** | %s |\n",
                $r['id'],
                $r['category'],
                implode(', ', $r['crops']) ?: '—',
                $r['top_score'] === null ? '—' : number_format($r['top_score'], 3),
                $r['expected'] ?? '—',
                $yesNo($r['supplied']),
                $yesNo($r['cited']),
                $r['coverage'] ?? '—',
                $r['verdict'],
                str_replace('|', '\\|', $r['reason'])
            );
        }

        $counts = array_count_values(array_column($rows, 'verdict'));
        $md .= sprintf(
            "\n**%d PASS · %d FAIL · %d REVIEW · %d ERROR.** REVIEW rows need a human verdict: read the "
            . "answer and its trace. ERROR rows are infrastructure failures (quota or overload), not behaviour.\n",
            $counts['PASS'] ?? 0,
            $counts['FAIL'] ?? 0,
            $counts['REVIEW'] ?? 0,
            $counts['ERROR'] ?? 0
        );

        $md .= "\n## Answers\n";

        foreach ($rows as $r) {
            $md .= "\n### {$r['id']} ({$r['category']}) — {$r['verdict']}\n\n";
            $md .= '**Answer:** ' . ($r['answer'] ?? '_(no model call)_') . "\n\n";

            if (trim((string) $r['not_covered']) !== '') {
                $md .= "**Not covered:** {$r['not_covered']}\n\n";
            }

            $md .= "Trace `{$r['trace']}`\n";
        }

        file_put_contents($path, $md);
    }
}
