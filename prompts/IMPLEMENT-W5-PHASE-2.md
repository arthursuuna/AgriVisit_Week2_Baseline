# Week 5 Phase 2 — give the agent a real job

Phase 1 built a loop that can call tools and stop for named reasons, but it has
nothing worth doing: no grounded knowledge, no instructions, and no way to start
a run outside a test. Phase 2 makes it do the actual work of the project.

After this phase, this should be true from the terminal:

> An officer states a goal. The agent looks up the farm, searches the curated
> corpus for guidance on that farm's outstanding issues, and proposes a visit —
> stopping at the approval boundary without writing anything. Every claim it
> makes about agronomy carries a chunk reference. Every step is traced.

The browser workflow and resuming after approval are Phase 3. Do not build them.

---

## 1. Scope

Four things, in this order.

| Part | What |
|---|---|
| A | `search_guidance` tool — the agent's access to the Week 3 corpus |
| B | The agent system prompt, versioned, in the project's six-part structure |
| C | The Agent Task Contract, generated from configuration |
| D | `agrivisit:agent` console command, so a run can be started and read |

Do not modify `app/Services/Agent/`, `app/Services/Llm/`, `Retrieval/`,
`Corpus/`, `Checklist/`, the checklist prompts, or the approval and audit code.
Part A adds a tool beside the existing ones; it does not change them.

---

## 2. Part A — the `search_guidance` tool

### Why this tool and not direct retrieval

The loop could call the `Retriever` itself, and that would be less code. It must
not. A tool call is recorded in the trace, counted against the iteration cap,
and validated by the gate; a direct service call is none of those. Searching is
an action the agent chose to take, and the evidence of Week 5 is the record of
actions it chose.

### Registration

Register in `AgriVisitServiceProvider` and add to `agrivisit.tools.enabled`.

- Access: **read**. No approval. It writes nothing.
- It must appear in `php artisan agrivisit:tools` output alongside the other
  three, because the catalogue is generated from the registry and must stay
  complete.

### Input schema

| Property | Type | Rules |
|---|---|---|
| `query` | string | Required. 3 to 300 characters. What to search the guidance corpus for. |
| `max_results` | integer | Optional. 1 to 5, default 3. |

Closed schema: any other property is `INVALID_ARGUMENTS`.

### Output schema

```json
{
  "query": "string, echoed back",
  "count": 0,
  "results": [
    {
      "chunk_ref": "string",
      "source_title": "string",
      "section": "string",
      "text": "string",
      "score": 0.0
    }
  ]
}
```

`count` is `results` length. An empty `results` array with `count: 0` is a
**successful** call, not a failure. The tool found nothing above the threshold
and said so; that is the correct outcome and the agent must be able to receive
it.

### Behaviour

1. Run the query through `RestrictedTopicGuard` **before** retrieving. If it is
   refused, the tool fails with the guard's reason.
2. Embed the query with the existing `Retriever` path — the same
   `gemini-embedding-001` configuration Week 3 evaluated. Do not add a code path
   that embeds differently.
3. Apply the configured **0.65** similarity threshold. Do not lower it, and do
   not add a fallback that returns the best results regardless of score. A
   threshold that bends under pressure is not a threshold.
4. Return at most `max_results` passages above it, highest score first.

### The guard must run on the agent's query, not just the officer's

This is the point of the part and should be stated in the code comment.

Week 4's guard protected what the **officer typed**. In Week 5 the agent composes
its own search queries from its own reasoning, and the officer never sees them
before they run. An agent asked to help with "leaf damage on maize" may decide on
its own to search for a fungicide application rate. That query was never typed by
a person and never reviewed by one.

So the guard runs inside the tool, on the agent-composed query, every time.

Add a test: a run whose goal is innocuous but whose model is scripted to call
`search_guidance` with a restricted query. The tool must fail, the failure must
appear in the trace, and nothing from the corpus may be returned.

### Tests

In `tests/Feature/SearchGuidanceToolTest.php`:

1. A query above threshold returns results, each with a non-empty `chunk_ref`.
2. A query below threshold returns `count: 0` and `ok: true`.
3. A restricted query fails and returns no passages.
4. `max_results` is honoured, and 6 is `INVALID_ARGUMENTS`.
5. Output validates against the tool's own output schema.

Use a fake retriever. No network, no key.

---

## 3. Part B — the agent system prompt

Write it to `resources/prompts/agent/v1.0/system.md` and load it through the
existing `PromptRepository`, the same way the checklist prompts are loaded. The
version is part of the path, so changing the prompt means a new version, not an
edit. Record the version in every trace.

Use the project's six-part structure, with these exact headings:

**Role.** An assistant helping a Ugandan agricultural extension officer prepare
for and carry out farm visits. The officer is the decision-maker; the assistant
proposes and never decides.

**Task.** Given the officer's stated goal, use the available tools to gather what
is needed and propose the actions that serve it. Take one action per turn.
Before each action, state in one or two sentences why you are taking it.

**Context.** Farm records, visits and follow-ups exist only behind tools — there
is no other way to read or change them, and nothing you state about a farm is
true unless a tool returned it. Agronomic guidance exists only in the curated
corpus reached through `search_guidance`. The officer's identity is configured by
the system; you do not ask who they are.

**Constraints.**
- Never state a pesticide, fungicide or herbicide dose, rate or concentration.
  Never give veterinary or clinical advice. If the goal requires it, say that it
  is outside what you can help with and stop.
- Every agronomic statement you make must cite the `chunk_ref` of a passage
  `search_guidance` returned to you. If you have no passage, say you did not find
  guidance. Do not supply it from your own knowledge.
- Never say an action has been taken. Write actions require the officer's
  approval, which happens outside this conversation.
- One tool call per turn.

**Output format.** Before each tool call: one or two sentences of reasoning. At
the end of the run: a short summary of what you found, what you are proposing,
and what is waiting on the officer. Plain sentences, no headings.

**Failure behaviour.** If a tool fails, say what failed and why in one sentence,
then either take a different action or stop and explain what you could not do.
Never repeat a call that just failed with the same arguments. Never fill a gap
with invented data. If you cannot make progress, say so and stop — stopping with
an explanation is a correct outcome.

### A note for the report

The last line is the one to point at. Every earlier week's prompt work tried to
make the model produce something. This is the first prompt that tells it when to
produce nothing, and that instruction is what makes the stop reasons meaningful
rather than decorative.

---

## 4. Part C — the Agent Task Contract

`php artisan agrivisit:agent-contract [--json]`.

Generate it from `config('agrivisit.agent')`, the tool registry and the prompt
file — **not** from a hand-written document. Same rule as the Week 4 tool
catalogue: one source, so the contract cannot describe a system that no longer
exists.

It must state:

| Section | Content |
|---|---|
| Purpose | What the agent is for, one paragraph, from the prompt's Role and Task |
| Inputs | The goal string, and the officer identity the system supplies |
| Tools available | Name, access, approval required — read from the registry |
| Bounds | Max iterations, max consecutive failures, runtime budget, one action per iteration |
| Stop reasons | All eight, each with the condition that produces it |
| Approval boundary | Which tools need approval, and that the loop never grants it |
| Prohibited | Dosing, veterinary advice, uncited agronomic claims, claiming a write happened |
| Evidence | Where traces are written and what each contains |

A test asserts that every tool in `agrivisit.tools.enabled` appears in the
contract and that every `StopReason` constant is listed. If a stop reason is
added later and the contract is not regenerated, the test fails.

---

## 5. Part D — the console command

`agrivisit:agent {goal} {--max-iterations=} {--dry-run}`

1. Build declarations from the enabled tools via `ClaudeSchemaTranslator`. Never
   declare the probe tools.
2. Load the system prompt from `resources/prompts/agent/v1.0/system.md`.
3. Resolve `BoundedAgentLoop` and run.
4. `--max-iterations` overrides the cap for this run only, so a cap can be made
   to fire without editing `.env`.
5. `--dry-run` prints the declarations and system prompt and exits without
   calling the model.

**Output.** For each step: the plan text wrapped above a row of iteration, tool,
outcome, duration. Then stop reason, iterations used against the cap, total
tokens, wall-clock seconds, and the trace file path.

Print the plan. A list of calls with no reasoning is not evidence of
goal-directed behaviour, and the plan is what makes the trace readable as a
sequence of decisions.

**On `AWAITING_APPROVAL`,** print the pending tool and arguments and state
plainly that nothing was written and that approval happens through the existing
Week 4 flow. The command must not approve anything.

One test with a fake `AgentModelClient`: exit code 0 and the stop reason appears
in the output. Do not test formatting.

---

## 6. Configuration

Add to `.env.example` and `.env`:

```
AGENT_PROMPT_VERSION=v1.0
```

Set `ANTHROPIC_FALLBACKS=` (empty) if it is still `default`, and add this comment
above it:

```
# Empty. A fallback model answering inside the same call would make the trace
# name a model that did not produce the reply.
```

---

## 7. Design rules

These are the rules the work is judged against. If one cannot be held, stop and
report rather than working around it.

1. **Searching is an action.** The agent reaches the corpus only through a tool,
   so every search is traced, counted and validated.
2. **The guard runs on agent-composed input.** Not only on what the officer
   typed.
3. **Finding nothing is a success.** Zero results above threshold is a valid tool
   result, and the agent must be able to report it rather than route around it.
4. **The threshold does not bend.** No fallback to best-available results.
5. **The prompt is a versioned file.** Changing it means a new version directory,
   and the version is recorded in the trace.
6. **The contract is generated.** From config, registry and prompt — never
   hand-maintained.
7. **Nothing writes without approval.** Phase 1's boundary is unchanged; this
   phase only gives the agent more to propose.

---

## 8. Report back

- Each part, with the files added.
- The `--dry-run` output, so the declarations and prompt can be read.
- One real run of
  `php artisan agrivisit:agent "Prepare me for a visit to farm UG-KYA-012"` —
  the printed steps and the trace file contents.
- Any place the brief was wrong, and what was done instead. The Phase 1 report's
  six deviations were the most useful part of it.
