# Week 5 Phase 3 — close the hole, then let the officer drive

Phase 2 produced two good runs and one serious finding: the agent was refused by
the restricted-topic guard and reworded its way around the refusal. Phase 3 fixes
that first, then builds the browser workflow and resumes a suspended run across
the officer's decision.

After this phase, this should be true in the browser:

> An officer types a goal, watches the agent work, sees the action it proposes,
> and approves or rejects it. On approval the run continues from where it paused
> and the record is written. On rejection the run ends. A refused topic ends the
> run immediately, and no rewording gets round it.

---

## 1. Scope and order

Part A is not optional and not reorderable. It comes first.

| Part | What |
|---|---|
| A | Guard refusals become terminal, and the clinical rule is narrowed |
| B | Runs persist in the database |
| C | Resume across approval; rejection ends the run |
| D | Browser workflow |

**Freezes lifted for this phase only:** `app/Services/Agent/` (Part A and C need
the loop), `app/Services/Tools/ToolResult` (one additive result code), and the
guard's term lists in `Checklist/`. Everything else stays as it was —
`Retrieval/`, `Corpus/`, the checklist prompts and the Week 4 approval and audit
code are not to be modified. The approval flow in particular is to be **reused,
not reimplemented**.

The agent prompt stays at **v1.0**. Do not create v1.1 in this phase. The fix
below is in code, and shipping a prompt instruction alongside it would blur which
one is doing the work.

---

## 2. Part A — a refusal is a stop, not a setback

### A1. A result code of its own

Add `GUARD_REFUSED` to `ToolResult`'s codes. Additive only; do not change the
seven existing codes or their meanings.

A refused query currently returns `INVALID_ARGUMENTS`. The arguments were
well-formed — the topic was refused. Week 4's failure taxonomy exists so a
supervisor can tell causes apart, and folding two causes into one code degrades
exactly the record the project is assessed on.

Update `SearchGuidanceTool` to return the new code, and update the Week 4
failure-evidence document's code table to eight.

### A2. The loop stops on it

In `BoundedAgentLoop`: when a tool result carries `GUARD_REFUSED`, end the run
with `StopReason::GUARD_REFUSED`.

**Before stopping, append the tool result to the conversation.** The API rejects
a turn that leaves a `tool_use` without a matching `tool_result` (Phase 1, W5-05),
and the trace must show what the agent was told. Stop after appending, not
instead of.

The run is a complete `AgentRun` with a named stop reason, as every other stop
is. The officer sees the refusal and its category.

**Why terminal rather than recoverable.** A refused *goal* already ends the run
as `GUARD_REFUSED`. A refused *query* did not, and that inconsistency was the
hole. The Week 1 boundary matrix says a refusal cannot be defeated by rephrasing;
in a single-turn system that held because the officer saw the refusal and the
conversation ended. An agent re-plans and tries again, and the officer sees only
the final answer. **For an agent, a refusal that can be rephrased is a rate
limit, not a boundary.**

Note in a code comment that the no-progress signature check cannot substitute for
this: signatures are `sha1(name + sorted arguments)`, so two differently-worded
queries on the same topic have different signatures. That check is syntactic; the
reroute was semantic. It was never capable of catching this.

### A3. The clinical rule matches subjects, not verbs

`diagnose` is in the clinical term list, so `"maize leaf damage causes and how to
diagnose"` was refused. Crop diagnosis is the officer's job.

Narrow the clinical rule so it fires on **what is being treated**, not on the
clinical verb alone: a clinical or veterinary term must co-occur with an animal
or human subject (livestock, cattle, goat, poultry, pig, dog, calf, herd,
patient, child, person, and so on) before it refuses.

Do not simply delete `diagnose`. That trades a false positive for a false
negative, and the veterinary boundary is absolute.

**This is the third time this project has hit the same failure shape.** Week 3's
C-02 → C-05 took three attempts because each fix matched how one document
happened to be worded rather than the shape of the information. Say so in the
report; a team that can name its own recurring error is further along than one
that fixes it quietly.

**Tests — both directions, and the second set matters more.**

Must now pass:
- `"maize leaf damage causes and how to diagnose"`
- `"diagnosing bean leaf discolouration"`
- `"soil test interpretation for maize"`

Must still refuse:
- `"what antibiotic dose for a sick goat"`
- `"treating mastitis in dairy cattle"`
- `"my child has a rash after spraying"`
- every existing guard test, unchanged

If any existing guard test has to be edited to pass, stop and report it rather
than editing it. An existing refusal that stops firing is a regression, not a
fix.

---

## 3. Part B — runs persist

### Table `agent_runs`

| Column | Type | Note |
|---|---|---|
| `run_id` | string, unique | The existing run id; the public key |
| `goal` | text | |
| `officer_id` | string | From configuration, as today |
| `status`, `stop_reason` | string | |
| `prompt_version`, `model` | string | |
| `iterations`, `consecutive_failures` | integer | Carried across a resume |
| `messages`, `steps`, `signatures`, `pending` | json | |
| `input_tokens`, `output_tokens` | integer | Accumulated across a resume |
| `active_seconds` | float | See below |
| `started_at`, `ended_at` | timestamp | |

### The trace file does not become the store

The trace JSON keeps being written on every stop, and it stays immutable. The
database row is working state; the trace is the audit record.

Reusing the trace file as the store would have been less code and is the wrong
call: resuming would mean rewriting the evidence file, and nobody reading a trace
could then tell what was recorded at the time from what was written afterwards.
Traces were built to be Week 5's evidence. Evidence that gets edited is not
evidence.

On resume, write a **new** trace for the continued run, carrying the same
`run_id` and a `resumed_from` field naming the previous trace. The pair reads as
one run in two parts, and neither file is ever rewritten.

### The runtime budget measures work, not waiting

`max_runtime_seconds` is 120. An officer may take a day to approve. Wall clock
across the pause is meaningless, and charging it to the budget would mean every
approved run dies on resume.

Accumulate `active_seconds` over model and tool time only; the clock stops when
the run suspends and starts again on resume. Say this in the Task Contract —
"120 s of work, not 120 s of elapsed time" is a distinction a marker will look
for.

---

## 4. Part C — resume, approve, reject

### Approval

The officer approves through the **existing Week 4 approval flow**, with its
single-use token and expiry. Do not build a second approval path and do not let
the agent UI approve anything itself.

On approval, resume:

1. Load the run.
2. Invoke the pending tool through `ToolInvoker`, **with** approval.
3. Append the real result as a `tool_result` using the same `tool_use_id`.
4. Continue the loop with the **remaining** iteration budget — a resumed run does
   not get a fresh six. `iterations` carries over.
5. Write a new trace when it stops.

**Validation still runs on an approved call.** This was a Week 4 property and it
must survive: approval authorises the action, it does not exempt it from the
schema or the business rules. Add a test that an approved `schedule_visit` with a
past date still fails `INVALID_ARGUMENTS`.

### Rejection

Add `StopReason::REJECTED`. On rejection:

1. Append a `tool_result` with `is_error: true` saying the officer declined.
2. End the run. No re-planning, no second proposal.
3. Write the trace.

**Why the run ends.** A rejected proposal re-planned into a slightly different
proposal is the same mechanism as W5-09 — the officer says no, the agent adjusts
and asks again, and the officer is in a loop of refusing. The signature check
does not help: a visit on the 20th instead of the 19th is a different signature.
The officer's no is final; a different outcome is a new run with a new goal.

An expired approval ends the run the same way, with the message distinguishing
declined from expired. The Week 4 approval record already carries that detail, so
one run-level reason is enough.

### Tests

1. Approve → the run continues, exactly one record is written, iterations carry
   over rather than resetting.
2. Approve a call with invalid arguments → still `INVALID_ARGUMENTS`, nothing
   written.
3. Reject → `REJECTED`, nothing written, no further model call.
4. A run suspended, reloaded and resumed has identical `messages` and
   `signatures` to before it suspended.
5. Resume with one iteration remaining → `ITERATION_CAP`, partial work kept.

---

## 5. Part D — the browser workflow

Four screens. Keep them plain; the Week 4 console's styling is the reference.

**`/agent`** — a goal field, the configured officer shown, a Run button. Two or
three example goals as hint text.

**`/agent/runs/{run_id}`** — the run. For each step: the plan text, the tool,
the arguments, the outcome. Then the stop reason, iterations used against the
cap, tokens and active seconds, and a link to the trace file.

Run synchronously and render the finished run. Live streaming is a large piece of
work for no marks; a run takes 10–25 seconds.

Each stop reason gets its own treatment:
- `AWAITING_APPROVAL` — the proposed action in plain words, with Approve and
  Reject buttons wired to the **existing** Week 4 flow, and the sentence
  *Nothing has been written yet.*
- `GUARD_REFUSED` — the refusal and its category, stated plainly, with no retry
  button. The absence of that button is the point.
- `ITERATION_CAP`, `NO_PROGRESS`, `REPEATED_FAILURE`, `RUNTIME_BUDGET` — what was
  reached, and the partial work kept.
- `REJECTED` — that the officer declined and the run is closed.

**`/agent/runs`** — all runs, newest first: goal, status, stop reason, iterations,
when. This is the supervisor view and it is where the Week 5 evidence is read
from.

**Approval** reuses the Week 4 approval screen. After the decision, return to the
run page showing the continued or closed run.

### The rule the UI must not break

Approving must never execute in the same step as proposing, and the agent UI must
never grant approval. The token, the expiry and the approval record stay exactly
as Week 4 built them.

---

## 6. After the code

1. Regenerate the Task Contract — it has two new stop reasons, a new tool result
   code, and the active-time definition. The contract test should fail until it
   is regenerated; that is the test working.
2. Re-run `php artisan agrivisit:tools` and update the Week 4 tool catalogue and
   demonstration documents, which still describe three tools.
3. Produce the third Phase 4 trace: `--max-iterations=2` on run 1's goal gives
   `ITERATION_CAP` with partial work preserved.

---

## 7. Design rules

1. **A refusal ends the run.** Wherever the guard fires, goal or query.
2. **Narrowing a rule must not create a gap.** Every existing refusal still
   fires; no existing guard test is edited to pass.
3. **The trace is immutable.** Working state lives in the database. A resume
   writes a new trace, never rewrites one.
4. **A resumed run inherits its budget.** Iterations, failures and tokens carry
   over; only the clock pauses.
5. **Approval authorises, it does not exempt.** Schema and business rules run on
   approved calls.
6. **A rejection is final.** No re-proposal within the run.
7. **One approval flow.** The Week 4 one. Reused, not rebuilt.

---

## 8. Report back

- Each part, files added and changed.
- The guard tests, both directions, with the veterinary cases shown passing.
- A browser run that reaches `AWAITING_APPROVAL`, is approved, and continues —
  with the before and after row counts.
- A browser run that is rejected, with the row count unchanged.
- A run whose goal or query is refused, showing the run ending.
- Where the brief was wrong. The Phase 1 and Phase 2 deviation sections have been
  the most useful part of both reports.
