<?php

namespace App\Providers;

use App\Services\Agent\AgentTraceWriter;
use App\Services\Agent\BoundedAgentLoop;
use App\Services\AgentTask\AgentTaskContract;
use App\Services\AgentTask\AgentToolset;
use App\Services\Checklist\ChecklistDrafter;
use App\Services\Checklist\ChecklistParser;
use App\Services\Checklist\RestrictedTopicGuard;
use App\Services\Checklist\TraceWriter;
use App\Services\Corpus\Chunker;
use App\Services\Corpus\RestrictedSectionStripper;
use App\Services\Corpus\TextExtractor;
use App\Services\Embedding\EmbeddingClient;
use App\Services\Embedding\GeminiEmbeddingClient;
use App\Services\Llm\ClaudeClient;
use App\Services\Llm\FunctionCalling\AgentModelClient;
use App\Services\Llm\FunctionCalling\ClaudeFunctionCallingClient;
use App\Services\Llm\FunctionCalling\FunctionCallingClient;
use App\Services\Llm\FunctionCalling\GeminiFunctionCallingClient;
use App\Services\Llm\FunctionCalling\SchemaTranslator;
use App\Services\Llm\GoogleClient;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use App\Services\Llm\OpenAiClient;
use App\Services\Prompts\PromptRepository;
use App\Services\Retrieval\CosineRetriever;
use App\Services\Retrieval\FarmQueryBuilder;
use App\Services\Retrieval\Retriever;
use App\Services\Tools\ApprovalPreview;
use App\Services\Tools\ApprovalService;
use App\Services\Tools\Catalogue\FarmProfileLookupTool;
use App\Services\Tools\Catalogue\FaultyProbeTool;
use App\Services\Tools\Catalogue\LogFollowupTool;
use App\Services\Tools\Catalogue\MalformedProbeTool;
use App\Services\Tools\Catalogue\RecallFarmHistoryTool;
use App\Services\Tools\Catalogue\ScheduleVisitTool;
use App\Services\Tools\Catalogue\SearchGuidanceTool;
use App\Services\Llm\FunctionCalling\ClaudeSchemaTranslator;
use App\Services\Tools\PendingCallStore;
use App\Services\Tools\SchemaValidator;
use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolInvoker;
use App\Services\Tools\ToolPolicy;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolSession;
use App\Support\FarmProfileRepository;
use Illuminate\Support\ServiceProvider;
use Smalot\PdfParser\Parser;

/**
 * Wires the Week 2 baseline.
 *
 * Register in bootstrap/providers.php (Laravel 11/12):
 *     App\Providers\AgriVisitServiceProvider::class,
 *
 * or in config/app.php under 'providers' (Laravel 10 and earlier).
 */
class AgriVisitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmClient::class, function () {
            $config = config('agrivisit.llm');

            return match ($config['provider']) {
                // Week 5: ClaudeClient supersedes AnthropicClient (kept, unused).
                'anthropic' => new ClaudeClient(self::claudeConfig()),
                'openai'    => new OpenAiClient($config),
                'google'    => new GoogleClient($config),
                default     => throw new LlmException('Unknown LLM provider: ' . $config['provider']),
            };
        });

        $this->app->singleton(PromptRepository::class, fn () => new PromptRepository(
            resource_path('prompts')
        ));

        $this->app->singleton(RestrictedTopicGuard::class, fn () => new RestrictedTopicGuard(
            config('agrivisit.restricted')
        ));

        $this->app->singleton(ChecklistParser::class, fn () => new ChecklistParser(
            config('agrivisit.checklist.min_items'),
            config('agrivisit.checklist.max_items')
        ));

        $this->app->singleton(TraceWriter::class, fn () => new TraceWriter(
            config('agrivisit.trace_path')
        ));

        $this->app->singleton(FarmProfileRepository::class, fn () => new FarmProfileRepository(
            storage_path('app/farm_profiles.json')
        ));

        // Bound with a default prompt version. The evaluation command overrides it
        // via makeWith(['promptVersion' => 'v1.0']) to compare versions.
        $this->app->bind(ChecklistDrafter::class, fn ($app, array $params) => new ChecklistDrafter(
            $app->make(LlmClient::class),
            $app->make(PromptRepository::class),
            $app->make(RestrictedTopicGuard::class),
            $app->make(ChecklistParser::class),
            $app->make(TraceWriter::class),
            $app->make(FarmQueryBuilder::class),
            $app->make(Retriever::class),
            $params['promptVersion'] ?? config('agrivisit.prompt_version')
        ));

        $this->app->singleton(TextExtractor::class, fn () => new TextExtractor(new Parser()));

        $this->app->singleton(RestrictedSectionStripper::class, fn () => new RestrictedSectionStripper(
            config('agrivisit.corpus.excluded_headings')
        ));

        $this->app->singleton(Chunker::class, fn ($app) => new Chunker(
            $app->make(RestrictedSectionStripper::class),
            config('agrivisit.corpus.chunk_words'),
            config('agrivisit.corpus.overlap_words'),
            config('agrivisit.corpus.min_words')
        ));

        $this->app->singleton(EmbeddingClient::class, fn () => new GeminiEmbeddingClient(
            config('agrivisit.embedding'),
            config('agrivisit.llm.google.key')
        ));

        $this->app->singleton(CosineRetriever::class, fn ($app) => new CosineRetriever(
            $app->make(EmbeddingClient::class),
            config('agrivisit.embedding.retrieve_k'),
            config('agrivisit.retrieval.threshold')
        ));

        $this->app->singleton(Retriever::class, fn ($app) => $app->make(CosineRetriever::class));

        // Week 4 tool layer. Registration is the allow-list; config decides
        // which registered tools are enabled.
        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = new ToolRegistry();
            $registry->register($app->make(FarmProfileLookupTool::class));
            $registry->register($app->make(ScheduleVisitTool::class));
            $registry->register($app->make(LogFollowupTool::class));
            $registry->register($app->make(SearchGuidanceTool::class));
            $registry->register($app->make(RecallFarmHistoryTool::class));

            // Test instrumentation only: absent from the allow-list unless switched on.
            if (config('agrivisit.tools.probes_enabled')) {
                $registry->register($app->make(FaultyProbeTool::class));
                $registry->register($app->make(MalformedProbeTool::class));
            }

            return $registry;
        });

        $this->app->singleton(ToolCallLog::class, fn () => new ToolCallLog(
            config('agrivisit.tools.log_path')
        ));

        $this->app->singleton(ToolInvoker::class, fn ($app) => new ToolInvoker(
            $app->make(ToolRegistry::class),
            $app->make(SchemaValidator::class),
            $app->make(ToolPolicy::class),
            $app->make(ToolCallLog::class)
        ));

        // Week 4 Phase 2: the model proposes calls; ToolInvoker still decides.
        $this->app->singleton(FunctionCallingClient::class, fn () => new GeminiFunctionCallingClient(
            config('agrivisit.llm')
        ));

        // Week 5: the agent loop talks to Claude in Claude's message shape, on
        // its own model setting, whatever LLM_PROVIDER says. Drafting, the Week 4
        // tool console and embeddings stay on Gemini (prompts/week5-claude-and-gemini.md).
        $this->app->singleton(ClaudeClient::class, fn () => new ClaudeClient(self::claudeConfig()));

        $this->app->singleton(AgentModelClient::class, fn ($app) => new ClaudeFunctionCallingClient(
            self::claudeConfig(),
            $app->make(ClaudeClient::class)
        ));

        // Write-once trace files; the run itself carries prompt version and model.
        $this->app->singleton(AgentTraceWriter::class, fn () => new AgentTraceWriter(
            config('agrivisit.agent.trace_path')
        ));

        // Week 5 Phase 2: the corpus as a tool, with the threshold Week 3 evaluated.
        $this->app->bind(SearchGuidanceTool::class, fn ($app) => new SearchGuidanceTool(
            $app->make(Retriever::class),
            $app->make(RestrictedTopicGuard::class),
            (float) config('agrivisit.retrieval.threshold')
        ));

        $this->app->bind(AgentToolset::class, fn ($app) => new AgentToolset(
            $app->make(ToolRegistry::class),
            $app->make(ToolPolicy::class),
            $app->make(ClaudeSchemaTranslator::class),
            $app->make(PromptRepository::class),
            config('agrivisit.agent.prompt_version')
        ));

        $this->app->bind(AgentTaskContract::class, fn ($app) => new AgentTaskContract(
            $app->make(AgentToolset::class),
            config('agrivisit.agent'),
            config('agrivisit.officer'),
            config('agrivisit.restricted'),
            config('agrivisit.llm.anthropic.model')
        ));

        $this->app->bind(BoundedAgentLoop::class, fn ($app) => new BoundedAgentLoop(
            $app->make(RestrictedTopicGuard::class),
            $app->make(AgentModelClient::class),
            $app->make(ToolInvoker::class),
            $app->make(AgentTraceWriter::class),
            // Only the limits: they are what the trace records under 'limits'.
            array_intersect_key(config('agrivisit.agent'), array_flip([
                'max_iterations', 'max_consecutive_failures', 'max_runtime_seconds',
            ])),
            null,
            (string) config('agrivisit.llm.anthropic.model')
        ));

        $this->app->bind(ToolSession::class, fn ($app) => new ToolSession(
            $app->make(RestrictedTopicGuard::class),
            $app->make(SchemaTranslator::class),
            $app->make(ToolRegistry::class),
            $app->make(ToolPolicy::class),
            $app->make(ToolInvoker::class),
            $app->make(FunctionCallingClient::class),
            $app->make(PromptRepository::class),
            new ToolCallLog(config('agrivisit.tools.session_log_path')),
            config('agrivisit.tools.prompt_version')
        ));

        // Week 4 Phase 3: held calls live in the cache; the browser holds only a token.
        $this->app->bind(PendingCallStore::class, fn ($app) => new PendingCallStore(
            $app->make('cache')->store(),
            (int) config('agrivisit.tools.approval_expiry_minutes', 10)
        ));

        $this->app->bind(ApprovalService::class, fn ($app) => new ApprovalService(
            $app->make(PendingCallStore::class),
            $app->make(ApprovalPreview::class),
            $app->make(ToolCallLog::class)
        ));
    }

    /** The shared llm settings with the Claude model in place of LLM_MODEL. */
    private static function claudeConfig(): array
    {
        return ['model' => config('agrivisit.llm.anthropic.model')] + config('agrivisit.llm');
    }
}
