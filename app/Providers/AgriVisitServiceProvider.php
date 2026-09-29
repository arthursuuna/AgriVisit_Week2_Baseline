<?php

namespace App\Providers;

use App\Services\Checklist\ChecklistDrafter;
use App\Services\Checklist\ChecklistParser;
use App\Services\Checklist\RestrictedTopicGuard;
use App\Services\Checklist\TraceWriter;
use App\Services\Corpus\Chunker;
use App\Services\Corpus\RestrictedSectionStripper;
use App\Services\Corpus\TextExtractor;
use App\Services\Llm\AnthropicClient;
use App\Services\Llm\GoogleClient;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use App\Services\Llm\OpenAiClient;
use App\Services\Prompts\PromptRepository;
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
                'anthropic' => new AnthropicClient($config),
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
    }
}
