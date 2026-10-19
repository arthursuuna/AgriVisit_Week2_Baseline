<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\AgentTask\McpInterfaceSpec;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Week 6 Phase 4: the interface specification is generated from the running
 * system, and refuses to generate when something is unaccounted for.
 */
class McpInterfaceSpecTest extends TestCase
{
    use RefreshDatabase;

    private function corpus(): void
    {
        $doc = Document::create([
            'doc_ref' => 'DOC-001', 'title' => 'Maize Guide', 'publisher' => 'MAAIF', 'licence' => 'Not stated',
            'source_url' => 'https://example.org/m.pdf', 'retrieved_on' => '2026-09-29', 'file_name' => 'm.pdf',
            'sections_removed' => 1, 'chunks_dropped' => 2, 'sentences_removed' => 3,
        ]);

        foreach (['Seed rate is 10 kg per acre.', 'Plant at the onset of rains.'] as $i => $text) {
            Chunk::create(['document_id' => $doc->id, 'chunk_ref' => sprintf('DOC-001-C%03d', $i + 1), 'position' => $i,
                'section' => '1.0', 'text' => $text, 'word_count' => 6]);
        }
    }

    public function test_the_spec_is_generated_from_the_tool_config_corpus_and_registry(): void
    {
        $this->corpus();
        config(['agrivisit.retrieval.threshold' => 0.71]);

        $this->assertSame(0, Artisan::call('agrivisit:mcp-spec'));
        $spec = Artisan::output();

        $this->assertStringNotContainsString('{{', $spec);
        $this->assertStringNotContainsString('(no reason given)', $spec);
        $this->assertStringContainsString('"name": "search_guidance"', $spec);
        $this->assertStringContainsString('"readOnlyHint": true', $spec);
        $this->assertStringContainsString('**0.71**', $spec);
        $this->assertStringContainsString('| `DOC-001` | Maize Guide | MAAIF | Not stated | 2 | 1 sections, 2 chunks, 3 sentences |', $spec);
        $this->assertStringContainsString('1 documents, 2 passages', $spec);
        $this->assertStringContainsString('It flagged **1**: `DOC-001-C001`', $spec, 'The dose screen runs over the index at generation.');

        foreach (['Capability', 'Inputs', 'Outputs', 'Permissions', 'Security boundary',
                  'What is deliberately not exposed, and why', 'Obligations that transfer to the caller', 'Errors'] as $section) {
            $this->assertStringContainsString("## {$section}", $spec);
        }

        // Every other registered tool is named, with its reason.
        foreach (app(ToolRegistry::class)->all() as $tool) {
            if ($tool->name() !== 'search_guidance') {
                $this->assertStringContainsString("| `{$tool->name()}` |", $spec);
            }
        }
    }

    public function test_every_result_code_is_classified_and_every_other_tool_has_a_reason(): void
    {
        config(['agrivisit.tools.probes_enabled' => true]);
        $this->app->forgetInstance(ToolRegistry::class);

        $this->assertSame([], McpInterfaceSpec::fromConfig()->problems());
    }

    public function test_an_unaccounted_tool_stops_generation(): void
    {
        $registry = app(ToolRegistry::class);
        $registry->register(new class extends \App\Services\Tools\Catalogue\FarmProfileLookupTool {
            public function __construct() {}
            public function name(): string { return 'brand_new_tool'; }
        });

        $this->assertSame(['Registered tool brand_new_tool has no reason for not being exposed.'], McpInterfaceSpec::fromConfig()->problems());
        $this->assertSame(1, Artisan::call('agrivisit:mcp-spec'));
    }
}
