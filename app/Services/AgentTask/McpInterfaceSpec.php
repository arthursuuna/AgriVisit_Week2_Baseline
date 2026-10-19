<?php

namespace App\Services\AgentTask;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Checklist\RestrictedTopicGuard;
use App\Services\Tools\Catalogue\SearchGuidanceTool;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolResult;

/**
 * The MCP-style interface specification for the one capability worth exposing:
 * the guidance corpus, as search_guidance (Week 6 Phase 4).
 *
 * The prose is resources/docs/mcp-interface.md. Everything that describes the
 * running system is generated into it: the tool definition and schemas (from
 * the tool), the threshold (config), the corpus and when it was built (the
 * documents and chunks tables), the guard's categories (config), the result
 * codes (ToolResult), the list of tools not exposed (the registry), and a dose
 * screen run over every indexed passage at generation time.
 *
 * A registered tool with no reason in NOT_EXPOSED, or a result code with no
 * entry in ERRORS, fails generation: nothing can be added to the system
 * without this document saying whether a caller sees it.
 */
class McpInterfaceSpec
{
    public const EXPOSED = 'search_guidance';

    /** Why every other registered tool stays inside. */
    public const NOT_EXPOSED = [
        'schedule_visit'      => 'It writes. Every write in this system is held for a named officer who sees what will change and decides. An interface cannot carry that boundary: approval is a person deciding, not a permission a caller can hold.',
        'log_followup'        => 'It writes, for the same reason.',
        'recall_farm_history' => 'It is the deployment\'s operational history: what its officers decided about its farms, shared across those officers by design, including their reasons in their own words. Exposing it would publish one organisation\'s working record, and replay free text written by its staff into a stranger\'s model (the injection surface the Memory Note describes).',
        'farm_profile_lookup' => 'Farm records are the deployment\'s own data: synthetic here, real farmers elsewhere.',
        'faulty_probe'        => 'Test instrumentation. Never offered to any model, and absent from the registry unless switched on.',
        'malformed_probe'     => 'Test instrumentation, as above.',
    ];

    /** Every result code, and what it means to an external caller of search_guidance (null: it cannot occur). */
    public const ERRORS = [
        null                             => 'Success. `count` may be 0.',
        ToolResult::INVALID_ARGUMENTS    => 'The arguments failed the input schema: `query` missing, under 3 or over 300 characters, `max_results` outside 1–5, or an unknown property. Nothing was searched. Fix the call; retrying it unchanged returns the same.',
        ToolResult::GUARD_REFUSED        => 'The query is about a restricted topic (pesticide dosing, veterinary or clinical advice). Nothing was retrieved. This is final for the topic: rewording the query to reach the same topic is the behaviour the guard exists to stop, and the agent is stopped for doing it.',
        ToolResult::SERVICE_UNAVAILABLE  => 'The search could not run, usually because the embedding service failed or timed out. Nothing was returned. Safe to retry later.',
        ToolResult::UNEXPECTED_RESPONSE  => 'The server produced results that do not match its own output schema, so they were discarded. A server fault, not a caller fault.',
        ToolResult::UNAUTHORIZED         => 'The capability is switched off in this deployment\'s configuration.',
        ToolResult::TOOL_NOT_FOUND       => 'The caller named a tool this server does not have.',
        ToolResult::APPROVAL_REQUIRED    => null,
        ToolResult::RESOURCE_NOT_FOUND   => null,
    ];

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly RestrictedTopicGuard $guard,
        private readonly array $restricted,
        private readonly float $threshold,
        private readonly string $embeddingModel,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            app(ToolRegistry::class),
            app(RestrictedTopicGuard::class),
            config('agrivisit.restricted'),
            (float) config('agrivisit.retrieval.threshold'),
            (string) config('agrivisit.embedding.model'),
        );
    }

    public function toMarkdown(): string
    {
        $tool = $this->tool();

        return strtr(file_get_contents(resource_path('docs/mcp-interface.md')), [
            '{{definition}}'     => $this->json($this->definition($tool)),
            '{{input_fields}}'   => $this->inputFields($tool),
            '{{output_schema}}'  => $this->json($tool->outputSchema()),
            '{{threshold}}'      => (string) $this->threshold,
            '{{max_results}}'    => (string) $tool->inputSchema()['properties']['max_results']['maximum'],
            '{{categories}}'     => implode(', ', array_map(fn ($c) => "`{$c}`", array_keys($this->restricted))),
            '{{corpus}}'         => $this->corpus(),
            '{{built}}'          => $this->built(),
            '{{screen}}'         => $this->screen(),
            '{{errors}}'         => $this->errors(),
            '{{not_exposed}}'    => $this->table(['Tool', 'Access', 'Why it is not exposed'], array_map(fn (ToolContract $t) => [
                "`{$t->name()}`", $t->isWrite() ? 'write' : 'read', self::NOT_EXPOSED[$t->name()] ?? '**(no reason given)**',
            ], $this->others())),
        ]);
    }

    /** @return list<string> problems that make the spec untrue; empty when it can be generated */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->others() as $t) {
            if (! isset(self::NOT_EXPOSED[$t->name()])) {
                $problems[] = "Registered tool {$t->name()} has no reason for not being exposed.";
            }
        }

        foreach ((new \ReflectionClass(ToolResult::class))->getConstants() as $code) {
            if (! array_key_exists($code, self::ERRORS)) {
                $problems[] = "Result code {$code} is not classified.";
            }
        }

        return $problems;
    }

    /** The tools/list entry an MCP server would publish. */
    public function definition(ToolContract $tool): array
    {
        return [
            'name'         => $tool->name(),
            'title'        => 'Search Ugandan agronomy guidance',
            'description'  => $tool->description(),
            'inputSchema'  => $tool->inputSchema(),
            'outputSchema' => $tool->outputSchema(),
            'annotations'  => [
                'readOnlyHint'    => ! $tool->isWrite(),
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ],
        ];
    }

    private function tool(): SearchGuidanceTool
    {
        return $this->registry->get(self::EXPOSED);
    }

    /** @return list<ToolContract> */
    private function others(): array
    {
        return array_values(array_filter($this->registry->all(), fn (ToolContract $t) => $t->name() !== self::EXPOSED));
    }

    private function inputFields(ToolContract $tool): string
    {
        $schema = $tool->inputSchema();
        $rows   = [];

        foreach ($schema['properties'] as $name => $p) {
            $rules = array_filter([
                in_array($name, $schema['required'] ?? [], true) ? 'required' : 'optional',
                $p['type'],
                isset($p['minLength']) ? "{$p['minLength']}–{$p['maxLength']} characters" : null,
                isset($p['minimum']) ? "{$p['minimum']}–{$p['maximum']}" : null,
                isset($p['default']) ? "default {$p['default']}" : null,
            ]);
            $rows[] = ["`{$name}`", implode(', ', $rules), $p['description']];
        }

        return $this->table(['Field', 'Rules', 'Meaning'], $rows)
            . "\n\nAny other property is rejected (`additionalProperties: " . json_encode($schema['additionalProperties'] ?? true) . '`).';
    }

    private function corpus(): string
    {
        $rows = Document::orderBy('doc_ref')->get()->map(fn (Document $d) => [
            "`{$d->doc_ref}`", $d->title, $d->publisher, (string) ($d->licence ?: 'Not recorded'),
            (string) Chunk::where('document_id', $d->id)->count(),
            "{$d->sections_removed} sections, {$d->chunks_dropped} chunks, {$d->sentences_removed} sentences",
        ])->all();

        return $this->table(['Document', 'Title', 'Publisher', 'Licence', 'Passages', 'Removed at ingestion'], $rows)
            . "\n\n" . Document::count() . ' documents, ' . Chunk::count() . ' passages, '
            . Chunk::whereNotNull('embedding')->count() . " embedded with `{$this->embeddingModel}`.";
    }

    private function built(): string
    {
        $documents = Document::max('created_at');
        $index     = Chunk::max('updated_at');

        return $documents === null ? 'The corpus is empty.'
            : 'Documents ingested ' . substr((string) $documents, 0, 10) . '; index last written ' . substr((string) $index, 0, 10)
                . '; sources retrieved ' . substr((string) Document::max('retrieved_on'), 0, 10) . '.';
    }

    /** The guard's output dose screen, run over every indexed passage now. */
    private function screen(): string
    {
        $flagged = Chunk::orderBy('chunk_ref')->get()->filter(fn (Chunk $c) => $this->guard->screenOutput($c->text) !== null);

        return "At generation, the dose screen (`RestrictedTopicGuard::screenOutput`) was run over all " . Chunk::count()
            . ' indexed passages. It flagged **' . $flagged->count() . '**'
            . ($flagged->isEmpty() ? '.' : ': ' . $flagged->pluck('chunk_ref')->map(fn ($r) => "`{$r}`")->implode(', ') . '.');
    }

    private function errors(): string
    {
        $rows = [];

        foreach (self::ERRORS as $code => $meaning) {
            $rows[] = [$code === '' ? '— (`ok: true`)' : "`{$code}`", $meaning ?? 'Never returned by this capability.'];
        }

        return $this->table(['Code', 'What it means to a caller'], $rows);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function table(array $head, array $rows): string
    {
        $lines = ['| ' . implode(' | ', $head) . ' |', '|' . str_repeat('---|', count($head))];

        foreach ($rows as $row) {
            $lines[] = '| ' . implode(' | ', $row) . ' |';
        }

        return implode("\n", $lines);
    }
}
