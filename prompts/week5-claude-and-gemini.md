# Week 5 Phase 1 — Claude for the agent loop, Gemini for everything else

Status (2026-10-12): **Phases 1–4 complete.** 274 tests passing. Committed on branch `week-5` (five commits, not pushed). Evidence in `evidence/week5-execution-traces.md` and `evidence/week5-boundaries.md`; Phase 4 report: `prompts/W5-phase-4-results.md`.
added terminal guard refusals, persisted runs, resume across the Week 4
approval flow and the browser workflow at `/agent` (results:
`prompts/W5-phase-3-results.md`). Ready for Phase 4. Not yet committed.

## 1. The decision in one table

| Capability | Provider | Model | Why |
|---|---|---|---|
| Checklist drafting (Week 3, `ChecklistDrafter`) | Gemini | `gemini-3.5-flash` | Evaluated and tuned on Gemini; unchanged behaviour |
| Week 4 tool console (`ToolSession`) | Gemini | `gemini-3.5-flash` | Builds Gemini-shaped messages; code is frozen for this phase |
| Embeddings and retrieval (Week 3) | Gemini | `gemini-embedding-001` | The 301 corpus vectors were built with it; Anthropic has no embeddings API |
| **Week 5 bounded agent loop** | **Claude** | **`claude-sonnet-5-5`** | New work; the multi-step tool loop the Week 5 brief specifies |

Both keys live in `.env` (gitignored): `GOOGLE_API_KEY` and `ANTHROPIC_API_KEY`.

## 2. Why two providers rather than one

We first tried switching everything to Claude. Two things stood in the way.

1. **Retrieval cannot move to Claude.** Anthropic offers no embeddings
   endpoint. The corpus index (301 chunks) and every search query use
   `gemini-embedding-001`, and vectors from different models cannot be
   compared. Leaving Gemini would mean either a third provider (for example
   Voyage AI, plus re-embedding the corpus) or replacing semantic search with
   keyword search. Either would invalidate the Week 3 retrieval evaluation and
   its 0.65 similarity threshold.
2. **The working Week 2–4 features would break or need rework.** Grounded
   drafting (`PROMPT_VERSION=v2.0`) depends on retrieval. The Week 4 tool
   console's `ToolSession` builds Gemini-format conversations and lives under
   `app/Services/Tools/`, which the Week 5 brief says not to modify.

So the split is drawn by task: **existing, evaluated features keep the provider
they were evaluated on, and the new Week 5 agent work runs on Claude.** Nothing
that already worked changes behaviour, and no evaluation result is invalidated.

Switching any single feature remains a configuration change, which is the
property that made the Week 2 → Week 4 model changes cheap. Every provider sits
behind an interface, and no Gemini code was deleted.

## 3. Why Claude for the agent loop

- **Full JSON Schema in tool declarations.** Claude's `input_schema` accepts
  `pattern`, `format`, `enum`, `maxLength` as they are. Gemini accepts only an
  OpenAPI subset, which is why the Week 4 `SchemaTranslator` folds those rules
  into descriptions. With Claude the schema is enforced in three places (the
  catalogue, the gate, and the model's own declaration) instead of two.
- **One action per iteration is enforceable at the API.**
  `tool_choice.disable_parallel_tool_use = true` means each model turn proposes
  at most one tool call, so the iteration cap is a true cap on actions taken.
- **Results are matched to calls by id.** `tool_result.tool_use_id` must match
  the `tool_use.id`, so a result can never be attached to the wrong call.

## 4. What was built

### Part A — Claude API client

| File | Purpose |
|---|---|
| `app/Services/Llm/ClaudeClient.php` | Single-turn Messages API client (`LlmClient`), plus the shared transport: headers, timeout, error mapping to `LlmException` |
| `app/Services/Llm/FunctionCalling/AgentModelClient.php` | Interface the loop depends on: Claude-shaped messages in, `ModelReply` out |
| `app/Services/Llm/FunctionCalling/ClaudeFunctionCallingClient.php` | Tool-calling client: sends tools with parallel use disabled; turns refusal, truncation and empty replies into `LlmException` |
| `app/Services/Llm/FunctionCalling/ModelReply.php` | The reply's content blocks kept exactly as received; `text()`, `plan()`, `toolCall()`, `toolCallCount()`, token counts |
| `app/Services/Llm/FunctionCalling/ToolCall.php` | `id`, `name`, `arguments`, and `signature()` (sha1 of name plus key-sorted arguments) |
| `app/Services/Llm/FunctionCalling/ClaudeSchemaTranslator.php` | Tool → `{name, description, input_schema}`, schema passed through unchanged |

### Part B — The bounded agent loop

| File | Purpose |
|---|---|
| `app/Services/Agent/StopReason.php` | The eight named ways a run can end |
| `app/Services/Agent/AgentStep.php` | One iteration: plan, tool, arguments, outcome, duration, tokens |
| `app/Services/Agent/AgentRun.php` | The run: conversation history, steps, signatures, pending call, status |
| `app/Services/Agent/BoundedAgentLoop.php` | The loop itself |
| `app/Services/Agent/AgentTraceWriter.php` | One JSON trace per run in `storage/app/agent-runs/`, written on every stop |

**Stop reasons:** `GOAL_REACHED`, `ITERATION_CAP` (6), `NO_PROGRESS` (repeated
call signature), `REPEATED_FAILURE` (3 failures in a row; a success resets the
count), `AWAITING_APPROVAL`, `RUNTIME_BUDGET` (120 s), `GUARD_REFUSED`,
`MODEL_ERROR`.

**Design rules held:** every exit is a named stop reason; the loop never passes
approval to `ToolInvoker::invoke()`; one action per iteration; a repeat stops the
run; a stopped run is a complete `AgentRun`, never an exception; every iteration
is traced.

### Wiring and configuration

- `config/agrivisit.php`: an `anthropic` block (model, key, workspace id,
  version, effort, thinking display, fallbacks, temperature switch) and an
  `agent` block (limits, trace path).
- `AgriVisitServiceProvider::register()`: binds `ClaudeClient` and
  `AgentModelClient` to the Claude model **independently of `LLM_PROVIDER`**,
  and binds `BoundedAgentLoop`. `LlmClient` and `FunctionCallingClient` stay on
  Gemini.
- Not modified: `app/Services/Tools/`, `Retrieval/`, `Corpus/`, `Checklist/`,
  the checklist prompts, approval and audit code.

### Tests (all with fakes: no key, no network)

- `tests/Feature/BoundedAgentLoopTest.php`: the 13 loop tests from the brief.
  Every run also asserts that no tool was invoked with approval and that a
  trace was written.
- `tests/Feature/ClaudeSchemaTranslatorTest.php`: 4 tests, including that the
  `farm_id` `pattern` survives (the opposite of the Gemini assertion).
- `tests/Feature/ClaudeFunctionCallingClientTest.php`: 5 tests checking the
  exact request shape against `Http::fake()`.
- Fakes: `tests/Fakes/FakeAgentModelClient.php`, `tests/Fakes/SpyToolInvoker.php`.

## 5. Where the brief needed changing

1. **Temperature.** Claude Sonnet 5.5 rejects a non-default `temperature` with
   HTTP 400. It is not sent (`ANTHROPIC_SEND_TEMPERATURE=false`), and traces
   record 1.0, the API default. `ANTHROPIC_EFFORT=medium` takes its place as the
   sampling-style setting; like Gemini's thinking level, it shares `max_tokens`.
2. **"Same interface as `GeminiFunctionCallingClient`."** Not possible without
   breaking `ToolSession`, which feeds that interface Gemini-format messages. A
   separate `AgentModelClient` interface was added instead.
3. **The step plan.** Between tool calls, Sonnet 5.5 returns its progress notes
   as `thinking` blocks that are empty by default. With
   `ANTHROPIC_THINKING_DISPLAY=updates` they are filled, and `plan()` falls back
   to them when the turn has no text.
4. **Extra tool calls.** If more than one `tool_use` ever arrives, the extras
   get "not executed" results, because the API rejects a turn that leaves a
   tool call without a result.
5. **Refusal fallback.** `ANTHROPIC_FALLBACKS=default` (beta) retries a
   safety-declined request on a fallback model inside the same call. Set it to
   empty to turn it off.
6. **Separate model setting.** Because `LLM_MODEL` stays a Gemini model, Claude
   reads its model from `ANTHROPIC_MODEL`.

## 6. Smoke test

After pasting the key into `ANTHROPIC_API_KEY` in `.env`:

```bash
php artisan config:clear
php artisan tinker --execute="dump(app(App\Services\Llm\ClaudeClient::class)->complete('Reply with one word.', 'Say: ready')->toArray());"
```

Record the model, token counts and latency here. If the API rejects the key and
names a workspace, set `ANTHROPIC_WORKSPACE_ID` (the `anthropic-workspace-id`
header is sent only when it is set), or create a workspace-scoped key.

| Attempt | Model | Input tokens | Output tokens | Latency | Result |
|---|---|---|---|---|---|
| 1, no workspace id | — | — | — | — | HTTP 400 `invalid_request_error`: "This API key is not scoped to a workspace, so this request must include the anthropic-workspace-id header with the ID of the workspace to use." (request `req_011CfguXzswVgaVY2ktyH7v`) |
| 2, workspace-scoped key | `claude-sonnet-5-5` | 21 | 5 | 2319 ms | **Success**, `stop_reason` `end_turn`, temperature recorded as 1.0 (not sent) |

**Finding (workspace header).** The user-scoped key (`sk-ant-usr-…`) was
rejected on the first call, as the console had warned. The error names the
header `anthropic-workspace-id`, which is exactly the header `ClaudeClient`
already sends when `ANTHROPIC_WORKSPACE_ID` is set, so no code change was
needed: the fix is configuration only.

**Resolution.** Rather than adding the workspace id, a new key was created in
the console scoped to the **Default** workspace (`Agrivisit_capstone_ws`), so
`ANTHROPIC_WORKSPACE_ID` stays unset. The original organization-scoped key is
to be deleted; it and the Google key were exposed in a screenshot and are being
rotated.

**Note on thinking tokens.** The trace shows `thinking_tokens: 0`. Claude does
not report thinking separately: any thinking is counted inside
`output_tokens`. The field is a Gemini concept kept for a stable trace shape.

## 7. Switching later

- **Agent loop back to Gemini:** not supported yet. The loop speaks Claude's
  message format; a Gemini `AgentModelClient` would be needed.
- **Drafting to Claude:** `LLM_PROVIDER=anthropic` (uses `ANTHROPIC_MODEL`).
  Re-run the prompt evaluation before relying on it.
- **Fully Claude:** needs a non-Gemini retriever (keyword search in PHP, or a
  third-party embeddings provider plus re-indexing) and a Claude version of the
  Week 4 tool console.

## 8. Handoff: state for Phase 2 onwards

Paste this section into the planning chat to brief the next phase.

**Environment, verified.** Gemini key active (drafting, Week 4 tool console,
embeddings). Claude key active, workspace-scoped, `claude-sonnet-5-5`, effort
`medium`, `max_tokens` 4000, timeout 25 s. Smoke call succeeded.

**Entry point for the agent.**

```php
$run = app(App\Services\Agent\BoundedAgentLoop::class)
    ->run(string $goal, array $declarations, string $systemPrompt);   // AgentRun
```

- `$declarations`: build with
  `app(ClaudeSchemaTranslator::class)->toToolDeclarations($tools)`. Phase 2
  decides which tools are offered (enabled ones from `ToolRegistry` plus the new
  `search_guidance`).
- `$systemPrompt`: Phase 2 supplies the real one (suggested location
  `resources/prompts/agent/v1.0/system.md`, loaded via `PromptRepository`).
- The loop invokes tools through `ToolInvoker`, so a new tool must be registered
  in `AgriVisitServiceProvider` and listed in `agrivisit.tools.enabled`.

**What Phase 2 needs to know.**

- `search_guidance` can use the existing `Retriever` (Gemini embeddings) inside
  the tool. The tool runs on our side; only its JSON result goes to Claude, so
  the provider split does not affect it.
- Tool input schemas reach Claude unchanged (full JSON Schema), so write the
  real constraints (`pattern`, `enum`, `maxLength`) into the schema.
- The Agent Task Contract should record these stated bounds: max 6 iterations,
  3 consecutive failures, 120 s runtime, parallel tool use disabled (one action
  per iteration), repeat signature stops the run, the loop never approves.

**What Phase 3 (resume after approval) needs.**

- On `AWAITING_APPROVAL`, `AgentRun::$pending` holds `tool`, `arguments`,
  `tool_use_id` and `summary`. The conversation ends with the assistant turn
  containing that `tool_use`.
- To resume: after the officer approves through the existing approval flow,
  append the tool result with `pushToolResultTurn()` using the same
  `tool_use_id`, then continue the loop. A rejection is also a `tool_result`
  (with `is_error: true`), so the model learns the officer declined.
- The run must be persisted between requests (it is in memory today; the trace
  JSON already contains `messages`, `steps`, `signatures` and `pending`).

**What Phase 4 (execution traces) needs.** Each run writes
`storage/app/agent-runs/{run_id}.json` with the goal, limits, every step (plan,
tool, arguments, outcome, duration, tokens), stop reason, summary and total
tokens. Three real runs (happy path, a recovery after a failure, a stop on a
limit or approval) can be taken straight from these files.

**Open items.**

1. ~~No real multi-step agent run yet.~~ Done in Phase 2: one `GOAL_REACHED`
   run and one `AWAITING_APPROVAL` run (nothing written). See
   `prompts/W5-phase-2-results.md`, including the guard finding (5.1).
2. Delete the old organization-scoped Anthropic key; rotate the Google key.
3. Commit the Phase 1 work.
