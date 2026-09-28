# Week 2 Baseline — Installation

Drop these files into a Laravel 11 or 12 application. Nothing here needs a package
beyond Laravel itself; the HTTP client and container bindings are framework built-ins.

## 1. Copy files

```text
config/agrivisit.php
app/Providers/AgriVisitServiceProvider.php
app/Services/Llm/            LlmClient, LlmResponse, LlmException, GoogleClient,
                             AnthropicClient, OpenAiClient
app/Services/Prompts/        PromptRepository
app/Services/Checklist/      ChecklistDrafter, RestrictedTopicGuard, ChecklistParser,
                             ChecklistFormatException, DraftResult, TraceWriter
app/Support/                 FarmProfileRepository
app/Http/Controllers/        ChecklistController
app/Console/Commands/        RunPromptEvaluation
resources/prompts/checklist/ v1.0/, v1.1/
resources/views/checklist/   index.blade.php
routes/web.php
storage/app/farm_profiles.json
database/evaluation/prompt_cases.json
tests/Feature/               RestrictedTopicGuardTest, ChecklistParserTest
```

## 2. Register the provider

Laravel 11/12 — add to `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AgriVisitServiceProvider::class,
];
```

Laravel 10 and earlier — add to the `providers` array in `config/app.php`.

## 3. Environment

Add to `.env` (and the placeholders to `.env.example`, without the real key):

```dotenv
LLM_PROVIDER=google
LLM_MODEL=gemini-3.5-flash
LLM_TEMPERATURE=0.2
LLM_MAX_TOKENS=1500
LLM_TIMEOUT=45
PROMPT_VERSION=v1.1

GOOGLE_API_KEY=
# ANTHROPIC_API_KEY=
# OPENAI_API_KEY=
```

Get a Google AI key from <https://aistudio.google.com/apikey>. It is sent as the
`x-goog-api-key` header, never as a URL query parameter, so it does not reach
proxy or server logs.

Switching provider is a configuration change, not a code change: set
`LLM_PROVIDER` to `google`, `anthropic` or `openai` and give that provider its
key. `LLM_MODEL` must name a model belonging to the selected provider.

The real key never gets committed. `.env` must already be in `.gitignore`.

## 4. Run

```bash
php artisan config:clear
php artisan serve
```

Open `http://127.0.0.1:8000`, pick a farm, submit. A draft checklist appears with
the model, temperature, token counts, latency and trace ID shown beneath it.

## 5. Run the tests

```bash
php artisan test --filter=RestrictedTopicGuardTest
php artisan test --filter=ChecklistParserTest
```

These cover the deterministic components and need no API key or network access.

## 6. Run the prompt evaluation

```bash
php artisan agrivisit:evaluate                  # active version (v1.1)
php artisan agrivisit:evaluate --prompt=v1.0    # the earlier version, for comparison
```

Each run writes `evaluation/prompt-eval-<version>.md` with the settings header and a
row per case, and drops one JSON trace per call into `storage/app/traces/`.

Cases marked **Review** are judgement calls the harness deliberately does not decide —
whether an item is genuinely doable on a farm, whether a rationale actually refers to
the profile. Open the trace, read the output, record the verdict yourself. That manual
step is the point: it is what makes the table evidence rather than a green tick.

## 7. Capture evidence

```bash
cp -r storage/app/traces evidence/traces
cp evaluation/prompt-eval-*.md evaluation/
```

Commit both, plus a screenshot of the console showing one drafted checklist and one
refusal.
