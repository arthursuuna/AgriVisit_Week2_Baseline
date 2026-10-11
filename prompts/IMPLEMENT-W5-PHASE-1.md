# Implement Week 5 Phase 1 — Claude API Migration and the Bounded Agent Loop

Two parts. **Part A** moves the application from Gemini to the Claude API.
**Part B** builds the bounded agent loop that Week 5 is about.

Part B depends on Part A, so do them in order. Neither part involves a new tool,
a UI change, or the approval-resume flow — those are Phases 2 and 3.

---

## 1. Context

AgriVisit drafts grounded field-visit checklists for Ugandan agricultural
extension officers (Week 3) and can call three approved tools behind a gate with
officer approval and an audit record (Week 4). Every tool call today is
**single-shot**: one request, at most one tool call, one answer.

Week 5 adds a bounded loop so the agent can carry out a task whose second step
cannot be known until the first returns. The task is:

> **Prepare and schedule a follow-up visit for a named farm.**

Look up the farm → learn its crops and outstanding issues → search the agronomy
corpus *using what was just learned* → draft a preparation summary → propose the
visit → hand off for approval.

The dependency is real: the retrieval query cannot be built before the lookup
returns, and the visit purpose cannot be chosen before the outstanding issues are
known.

### Do not modify

`app/Services/Tools/` (the gate, the policy, the three tools, the probes),
`app/Services/Retrieval/`, `app/Services/Corpus/`, `app/Services/Checklist/`,
`resources/prompts/checklist/`, the approval and audit code.

New files, plus additions to `config/agrivisit.php` and
`AgriVisitServiceProvider::register()`.

---

# PART A — Claude API

## 2. The key

An Anthropic API key has been placed in `.env` by hand. **Never write the key
into any file under version control**, into a report, a log, or a commit.

```dotenv
LLM_PROVIDER=anthropic
LLM_MODEL=claude-sonnet-5-5
LLM_TEMPERATURE=0.2
LLM_MAX_TOKENS=4000
LLM_TIMEOUT=25
ANTHROPIC_API_KEY=
# ANTHROPIC_WORKSPACE_ID=      # only if the API asks for it, see below
```

Confirm `.env` is gitignored and that `.env.example` carries the variable names
with empty values.

**On the workspace.** The key issued is a user-scoped key
(`sk-ant-usr-…`) and the console noted that a workspace id may need to travel
with requests. Make the first smoke call without one. If the API rejects it with
a message naming a workspace, add the header **exactly as the error names it**
and read the id from `ANTHROPIC_WORKSPACE_ID`. Do not guess a header name — if
the error is unclear, report it rather than trying variations. Creating a
workspace-scoped key in the console is also a valid fix; say so if that is
simpler.

**Keep `LLM_TIMEOUT` below PHP's `max_execution_time`.** That alignment was fixed
in Week 4 and must not regress: the HTTP client always times out before the
process does, so a slow call is a handled failure rather than a fatal error.

## 3. What changes, and what does not

The provider sits behind `LlmClient` and behind whatever interface
`GeminiFunctionCallingClient` implements. **Add Claude implementations beside the
Gemini ones; delete nothing.** Switching provider must remain a configuration
change, which is the property that made the Week 2 → Week 4 model changes cheap.

`EMBEDDING_MODEL` and the embedding client are **unchanged**. The 301 corpus
vectors were built with `gemini-embedding-001` and remain valid. Retrieval
continues to use Gemini embeddings; only generation moves to Claude. Record this
split plainly — it is a real architectural detail, not an oversight.

## 4. `app/Services/Llm/ClaudeClient.php`

Implements the existing `LlmClient` interface. Match the current `LlmResponse`
constructor signature rather than the one sketched here if they differ.

```php
<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Single-turn completion against the Claude Messages API.
 *
 * Differences from the Gemini client, all of which matter:
 *   - the model is in the request body, not the URL path
 *   - the system prompt is a top-level parameter, not a message role
 *   - max_tokens is required, not optional
 *   - the key travels in x-api-key, and anthropic-version is mandatory
 *   - the response body carries a content array of typed blocks
 */
class ClaudeClient implements LlmClient
{
    public function __construct(private readonly array $config)
    {
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    public function complete(string $system, string $user): LlmResponse
    {
        $body = $this->send([
            'model'       => $this->config['model'],
            'max_tokens'  => $this->config['max_tokens'],
            'temperature' => $this->config['temperature'],
            'system'      => $system,
            'messages'    => [
                ['role' => 'user', 'content' => $user],
            ],
        ], $latencyMs);

        $text = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'];
            }
        }

        if (trim($text) === '') {
            throw new LlmException('Model returned an empty completion.');
        }

        return new LlmResponse(
            text: $text,
            provider: $this->provider(),
            model: $body['model'] ?? $this->config['model'],
            temperature: $this->config['temperature'],
            inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            latencyMs: $latencyMs,
            stopReason: $body['stop_reason'] ?? null,
        );
    }

    /**
     * Shared transport. Converts every transport and HTTP failure into
     * LlmException so nothing escapes to an error page.
     */
    public function send(array $payload, ?int &$latencyMs = null): array
    {
        $key = $this->config['anthropic']['key'] ?? null;

        if (empty($key)) {
            throw new LlmException('ANTHROPIC_API_KEY is not set.');
        }

        $headers = [
            'x-api-key'         => $key,
            'anthropic-version' => $this->config['anthropic']['version'],
            'content-type'      => 'application/json',
        ];

        if (! empty($this->config['anthropic']['workspace_id'])) {
            // Only sent when configured; see the note on user-scoped keys.
            $headers['anthropic-workspace-id'] = $this->config['anthropic']['workspace_id'];
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($this->config['timeout'])
                ->withHeaders($headers)
                ->post($this->config['anthropic']['base'], $payload);
        } catch (Throwable $e) {
            throw new LlmException('Model request failed: ' . $e->getMessage(), 0, $e);
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            throw new LlmException(
                'Model returned HTTP ' . $response->status() . ': ' . $response->body(),
                $response->status()
            );
        }

        return $response->json();
    }
}
```

Config block to merge into the existing `llm` section:

```php
'anthropic' => [
    'key'          => env('ANTHROPIC_API_KEY'),
    'workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),
    'base'         => 'https://api.anthropic.com/v1/messages',
    'version'      => '2023-06-01',
],
```

## 5. Schema translation becomes near-passthrough

Gemini's function declarations accept only an OpenAPI subset, so the Week 4
`SchemaTranslator` folds `pattern`, `format`, `maxLength` and the rest into each
property's description.

**Claude's `input_schema` is full JSON Schema.** Those keywords are accepted as
they are. Add `ClaudeSchemaTranslator`, which produces:

```php
[
    'name'         => $tool->name(),
    'description'  => $tool->description(),
    'input_schema' => $tool->inputSchema(),   // passed through unchanged
]
```

Keep the Gemini translator untouched. Add a test asserting that a Claude
declaration **retains** the `pattern` on `farm_id`, since that is the behavioural
difference and the reason the Week 4 description-folding is no longer needed.

Record this in the findings log: the constraint that shaped a Week 4 design
decision does not exist on this provider, and the schema is now enforced in three
places rather than two — the gate, the catalogue, and the model's own declaration.

## 6. `app/Services/Llm/FunctionCalling/ClaudeFunctionCallingClient.php`

Implements the same interface as `GeminiFunctionCallingClient`. This is the class
the loop drives, so the request and response shapes must be exact.

**Request:**

```php
$payload = [
    'model'       => $this->config['model'],
    'max_tokens'  => $this->config['max_tokens'],
    'temperature' => $this->config['temperature'],
    'system'      => $systemPrompt,
    'messages'    => $messages,
    'tools'       => $declarations,
    'tool_choice' => [
        'type' => 'auto',
        'disable_parallel_tool_use' => true,
    ],
];
```

`disable_parallel_tool_use` is deliberate and is a stated bound, not a
convenience: **one action per iteration** makes the iteration cap a true cap on
actions taken, and keeps each trace entry a single decision. Record it in the
Agent Task Contract in Phase 2.

**Response.** `content` is an array of typed blocks. A reply may hold `text`
blocks, a `tool_use` block, or both:

```json
{
  "content": [
    {"type": "text", "text": "I'll look up that farm first."},
    {"type": "tool_use", "id": "toolu_01A…", "name": "farm_profile_lookup",
     "input": {"farm_id": "UG-KYA-012"}}
  ],
  "stop_reason": "tool_use",
  "usage": {"input_tokens": 912, "output_tokens": 84}
}
```

**Returning a result** means appending two turns and resending the whole
conversation — the API is stateless:

```php
// 1. the assistant's own turn, content array preserved exactly as received
$messages[] = ['role' => 'assistant', 'content' => $reply->rawContent()];

// 2. the result, keyed by tool_use_id — not by tool name
$messages[] = ['role' => 'user', 'content' => [[
    'type'        => 'tool_result',
    'tool_use_id' => $call->id,
    'content'     => json_encode($result->toArray()),
    'is_error'    => ! $result->ok,
]]];
```

Two rules that are easy to get wrong:

- **`tool_use_id` must match the id from the `tool_use` block.** Gemini matched
  results to calls by function name; Claude matches by id. A mismatched id is
  rejected.
- **Resend the assistant turn verbatim.** Rebuilding it from the tool name and
  arguments loses the other blocks in it. The Week 4 finding about not
  reconstructing model turns applies here for the same reason, even though the
  mechanism differs.

Return a value object — `ModelReply` — exposing:

| Method | Returns |
|---|---|
| `rawContent()` | the content array exactly as received |
| `text()` | all `text` blocks concatenated |
| `hasToolCall()` | whether any `tool_use` block is present |
| `toolCall()` | the first `tool_use` as a `ToolCall`, or null |
| `toolCallCount()` | how many `tool_use` blocks arrived |
| `stopReason()`, `inputTokens()`, `outputTokens()` | as returned |

`ToolCall` carries `id`, `name`, `arguments`, and:

```php
public function signature(): string
{
    $args = $this->arguments;
    $this->sortDeep($args);            // ksort recursively

    return sha1($this->name . '|' . json_encode($args));
}
```

Sorting before hashing matters: `{a:1,b:2}` and `{b:2,a:1}` are the same call and
must produce the same signature.

## 7. Smoke test

One real call, to prove the key and headers work before building on them:

```bash
php artisan tinker
>>> app(App\Services\Llm\LlmClient::class)->complete('Reply with one word.', 'Say: ready')
```

Report the model name, token counts and latency it returns. If it fails, report
the HTTP status and the error body verbatim.

---

# PART B — The bounded agent loop

## 8. Design rules

1. **Bounded means enumerable.** Every way the loop can stop is a named constant.
   There is no path out of the loop that is not one of them.
2. **The loop never approves.** `invoke()` is always called without approval.
   There is no argument, flag or branch by which the loop can set it. Approval is
   a human act, and resuming after one is Phase 3.
3. **One action per iteration.** Parallel tool use is disabled, so the iteration
   cap bounds the number of actions taken, not just the number of model calls.
4. **A repeat is not progress.** The tools are deterministic: the same tool with
   the same arguments cannot return new information. A repeated signature stops
   the run.
5. **Partial work survives a stop.** Every stop reason produces a complete
   `AgentRun` with its steps, not an exception. A run that hit a limit is still a
   run you can read.
6. **Every iteration is traced** — plan, action, arguments, outcome code,
   duration — because Phase 4's three execution traces are built from this.

## 9. Stop reasons — `app/Services/Agent/StopReason.php`

```php
<?php

namespace App\Services\Agent;

final class StopReason
{
    /** The model answered without calling a tool. The task is done. */
    public const GOAL_REACHED = 'GOAL_REACHED';

    /** max_iterations reached. Partial results are handed to the officer. */
    public const ITERATION_CAP = 'ITERATION_CAP';

    /** The same tool was called with the same arguments twice. */
    public const NO_PROGRESS = 'NO_PROGRESS';

    /** max_consecutive_failures tool failures in a row; the agent is not recovering. */
    public const REPEATED_FAILURE = 'REPEATED_FAILURE';

    /** A write tool needs the officer. The run suspends here; Phase 3 resumes it. */
    public const AWAITING_APPROVAL = 'AWAITING_APPROVAL';

    /** Wall-clock budget exhausted. */
    public const RUNTIME_BUDGET = 'RUNTIME_BUDGET';

    /** The request was refused by the restricted-topic guard before anything ran. */
    public const GUARD_REFUSED = 'GUARD_REFUSED';

    /** The model could not be reached or returned an unusable reply. */
    public const MODEL_ERROR = 'MODEL_ERROR';
}
```

## 10. Config

```php
'agent' => [
    'max_iterations'           => (int) env('AGENT_MAX_ITERATIONS', 6),
    'max_consecutive_failures' => (int) env('AGENT_MAX_FAILURES', 3),
    'max_runtime_seconds'      => (int) env('AGENT_MAX_RUNTIME', 120),
    'trace_path'               => storage_path('app/agent-runs'),
],
```

## 11. `AgentStep` and `AgentRun`

`AgentStep` is one iteration, recorded whatever the outcome:

| Field | Meaning |
|---|---|
| `iteration` | 1-based |
| `plan` | the model's text in that turn, if any — its stated reasoning |
| `tool` | tool name, or null when the model only spoke |
| `arguments` | as proposed |
| `outcome` | the `ToolResult` code, or `OK` |
| `duration_ms` | tool execution time |
| `input_tokens`, `output_tokens` | that model call |

`AgentRun` holds: `run_id`, `goal`, `started_at`, `ended_at`, `iterations`,
`consecutive_failures`, `status` (`running` while in flight, otherwise the stop
reason), `summary` (the final text or the stop explanation), `steps[]`,
`pending` (set only on `AWAITING_APPROVAL`), the seen-signature set, and the
message history.

It must expose `history()` for the next model call, `pushUserTurn()`,
`pushAssistantTurn(array $rawContent)`, `pushToolResultTurn()`,
`hasSeenSignature()`, `recordSignature()`, `elapsedSeconds()`, and `toArray()`
for the trace.

## 12. `app/Services/Agent/BoundedAgentLoop.php`

The whole loop. Declarations and the system prompt are passed in so the loop
stays testable without the real catalogue — Phase 2 supplies the real ones.

```php
public function run(string $goal, array $declarations, string $systemPrompt): AgentRun
```

In order:

1. **Guard the request.** Refused → stop `GUARD_REFUSED`. The model is never
   called.
2. Push the goal as the first user turn.
3. **Loop:**
   1. `iterations >= max_iterations` → stop `ITERATION_CAP`.
   2. `elapsedSeconds() >= max_runtime_seconds` → stop `RUNTIME_BUDGET`.
   3. Call the model with the system prompt, the history and the declarations.
      An `LlmException` → stop `MODEL_ERROR`, keeping the steps so far.
   4. Increment the iteration count; push the assistant turn with its raw
      content.
   5. No `tool_use` block → stop `GOAL_REACHED`, with the reply text as the
      summary.
   6. Take the first `tool_use`. If `toolCallCount() > 1`, record that in the
      step (it should not happen with parallel use disabled, and is worth
      knowing if it does).
   7. Signature already seen → stop `NO_PROGRESS`. Record the step first, so the
      trace shows the repeat that caused the stop.
   8. Invoke through `ToolInvoker` **without approval**.
   9. Record the step.
   10. `APPROVAL_REQUIRED` → stop `AWAITING_APPROVAL`, carrying the pending call.
   11. Failure → increment `consecutive_failures`; at the limit stop
       `REPEATED_FAILURE`. Success → reset it to zero.
   12. Push the tool result turn and continue.
4. On every stop: set `ended_at`, write the trace, return the run.

A failure followed by a success **resets** the counter. The limit is about an
agent that is not recovering, not about total failures — an agent that fails
once, adapts and succeeds is the behaviour Phase 4's recovery trace demonstrates.

## 13. `app/Services/Agent/AgentTraceWriter.php`

One JSON file per run in `storage/app/agent-runs/`, named by `run_id`: the goal,
limits in force, every step, the stop reason, the summary, total tokens and total
duration. Written on every stop, including `GUARD_REFUSED` and `MODEL_ERROR`.

---

## 14. Tests

All of Part B runs with a **fake function-calling client** returning scripted
replies. No API key, no network, no quota.

`tests/Feature/BoundedAgentLoopTest.php`

1. A scripted run — two tool calls then a text answer — stops `GOAL_REACHED` with
   three steps and the final text as the summary.
2. A client that always calls a tool stops `ITERATION_CAP` with exactly
   `max_iterations` steps, and the run is still readable.
3. The same tool with the same arguments twice stops `NO_PROGRESS` on the second,
   and that second call is in the trace.
4. The same tool with **different** arguments twice does **not** stop.
5. Arguments differing only in key order produce the same signature and do stop.
6. Three consecutive failing tool results stop `REPEATED_FAILURE`.
7. A failure, then a success, then a failure does **not** stop — the counter
   resets.
8. A write tool returning `APPROVAL_REQUIRED` stops `AWAITING_APPROVAL`, carries
   the pending call, and **writes nothing** — assert the `visits` count is
   unchanged.
9. A restricted goal stops `GUARD_REFUSED` and the fake client is never called —
   assert its call count is zero.
10. An `LlmException` mid-run stops `MODEL_ERROR` and the steps already taken are
    preserved.
11. The runtime budget stops the run when exceeded.
12. **The loop never invokes a tool with approval.** Use a spy invoker and assert
    the approved flag was false on every call, under every scenario above.
13. Every run writes a trace file containing one entry per step and the stop
    reason.

Test 12 is the one that proves design rule 2, exactly as its Week 4 counterpart
proved that the model could not approve its own call.

`tests/Feature/ClaudeSchemaTranslatorTest.php`

1. All three tools translate without error.
2. `farm_id`'s `pattern` **survives** translation — the opposite of the Gemini
   assertion.
3. `enum`, `maxLength` and `format` survive.
4. The declaration has exactly `name`, `description` and `input_schema`.

## 15. Verification

```bash
php artisan config:clear
php artisan test
php artisan tinker     # then the smoke call from section 7
```

Report: the full suite result, the smoke call's model and token counts, and the
stop reason each of the thirteen loop tests exercised.

## 16. Out of scope

The `search_guidance` tool, the Agent Task Contract document, the real system
prompt, any UI, resuming a suspended run after approval, and the execution traces
for the deliverable. Phases 2, 3 and 4.

If you find yourself editing `ToolInvoker`, `ApprovalService` or anything under
`app/Services/Tools/`, stop — you have left this phase.

## 17. Report back

State the files added, the test results, the smoke-call outcome, and anything in
this brief that proved wrong or ambiguous — particularly about the Claude request
or response shape, or the workspace-id question. If a design rule in section 8
could not be satisfied, say which and why rather than working around it silently.
