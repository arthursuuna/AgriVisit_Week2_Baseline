# Week 6 Phase 1 — The State Model, Written Down and Enforced: Results

**Implemented from:** `prompts/IMPLEMENT-W6-PHASE-1.md`
**Date:** 2026-10-19
**Status:** All four parts complete. **287 tests pass** (1490 assertions): the 274 that already existed, three of them changed (section 6), plus 13 new ones in `RunStateTest`. No live model runs were needed, because the agent's behaviour did not change. Nothing has been committed.

| Part | State |
|---|---|
| A. Name the states | Done. `RunState`: three states plus `—` (no state), six legal transitions, six illegal pairs, each with a reason |
| B. Enforce them | Done. Checked in memory (`AgentRun`), at the store (`AgentRunStore::save`) and under a per-run lock (`AgentRunner`). Illegal moves throw `IllegalRunTransition` |
| C. Generate the document | Done. `php artisan agrivisit:state-model [--json]`. Output saved to `evidence/week6-state-model.md` and copied in full in section 7 |
| D. Tests | Done. `tests/Feature/RunStateTest.php`: the six illegal-move tests, the full legal path, the race, the browser double-submit, and three document tests |

## 1. Files

### Added

| File | Purpose |
|---|---|
| `app/Services/AgentTask/RunState.php` | States, transitions, illegal pairs and why, `check()` (the only place that answers "may this run move from X to Y?") |
| `app/Services/AgentTask/IllegalRunTransition.php` | The exception (a `LogicException`), with `between()` and `lostRace()` |
| `app/Services/AgentTask/RunStateModel.php` | Builds the document from `RunState` and `Schema::getColumnListing('agent_runs')` |
| `app/Console/Commands/StateModel.php` | `agrivisit:state-model [--json]` |
| `tests/Feature/RunStateTest.php` | Part D |

### Changed

| File | Change |
|---|---|
| `app/Services/Agent/AgentRun.php` | `begin()` (— → RUNNING). `stop()` checks the move. `beginSegment(bool $approved = true)` checks the move. A declined segment no longer passes through RUNNING (section 2) |
| `app/Services/Agent/BoundedAgentLoop.php` | `run()` calls `$run->begin()` where it used to call `pushUserTurn($goal)`. `openSegment()` hands its check to `beginSegment()`, so the old ad-hoc `LogicException` status test is gone. No decision logic changed |
| `app/Services/AgentTask/AgentRunStore.php` | `save()` refuses to write over a closed row, and refuses to save a waiting row except as its next segment |
| `app/Services/AgentTask/AgentRunner.php` | `decide()` and `closeIfLapsed()` run under `exclusively()`: a per-run lock, a re-read, then `RunState::check()`, all before the token is touched. `closeIfLapsed()` logs a lost race as a warning |
| `app/Http/Controllers/AgentController.php` | Catches `IllegalRunTransition` on a decision, logs it, and shows the existing "already used" banner, so the officer sees what they saw before |
| `tests/Feature/AgentResumeTest.php` | Three tests changed (section 6) |

**Not modified:** the loop's decision logic, the tools, the guard, `ApprovalService` / `PendingCallStore` / the approval panel, the views, the agent prompt (still `agent/v1.0`), and the schema (no migration).

## 2. The states as found, and what did not fit the brief's table

The three states in the brief are real, but the code did not match the table in three places:

1. **RUNNING is never stored.** `agent_runs` is written only when a run stops or suspends. A new run has no row until it stops, and a resuming run's row still says `AWAITING_APPROVAL` until the resume ends. So "no run should be found in RUNNING between requests unless something crashed" is true in a stronger sense: a run is never found in RUNNING at all. What a crash actually leaves is a row showing `AWAITING_APPROVAL` whose token has already been used. That row looks as if it is waiting forever, and approving it returns "already used". I documented this in the state description and did not change it, because storing RUNNING would change what other requests see mid-run, and this phase is not meant to change behaviour. **Recommendation for Phase 2:** store RUNNING when a decision is accepted, so a crash shows as a crash.
2. **`— → CLOSED` and `— → RUNNING` had no code behind them.** In memory a run was `running` from the moment it was constructed, so a refused goal went RUNNING → CLOSED. I added `AgentRun::begin()`, which puts the goal into the conversation, and a run with no messages now counts as `—`. That makes both rows of the brief's table literal, and it means `— → CLOSED` is legal only for `GUARD_REFUSED`.
3. **A rejection used to pass through RUNNING.** `reject()` opened a segment, which set the status to `running`, and then stopped as `REJECTED`. That is the path AWAITING → RUNNING → CLOSED, not AWAITING → CLOSED. `beginSegment(false)` now leaves the run waiting until `stop(REJECTED)` closes it. Nothing observable changed: no request ever saw the in-between `running`.

There are no further states. Each stop reason maps to exactly one state (a test checks this): `AWAITING_APPROVAL` means waiting, and every other stop reason means closed. Some ideas sound like extra states, such as "expired" or "refused". They are not: each is a CLOSED run told apart by its stop reason or summary, and none of them allows a different move.

**Conditional rules, not just pairs.** The brief lists pairs, but two pairs are legal only for certain stop reasons. `RunState::check()` enforces both:
- `AWAITING_APPROVAL → CLOSED` only as `REJECTED`.
- `RUNNING → CLOSED` never as `REJECTED`, because there is no held call to reject.

## 3. Illegal moves that succeeded before this phase

I wrote a throwaway probe test against the unchanged code. Findings:

| Attempt | Before | Consequence |
|---|---|---|
| `AgentRunStore::save()` of a closed run with any status | **Succeeded.** The probe reopened a REJECTED run as `AWAITING_APPROVAL` | No app code calls it this way today. But the store was the one thing that would let any caller reopen a closed run, a rejected one included, and nothing would have noticed. "A rejection is final" was true only because every current caller happened to behave |
| A new run saved under an existing (refused) run id | **Succeeded** (`updateOrCreate`) | A refused run could be overwritten by a fresh one under the same id, and its record replaced |
| Approve / reject a closed or never-waiting run through `AgentRunner::decide()` | Returned `UNKNOWN` quietly, nothing changed | Not unsafe, but it was the silent `false` the brief warns about: a caller bug and a stale page looked the same |
| A second decision on the same held call | Returned `ALREADY_USED` quietly (the token store caught it) | Same as above |
| Two concurrent closes after the store had forgotten the token | The loser **threw `RuntimeException: Trace … already exists`** | This only failed because the trace writer happens to open files with `'x'`. Nothing in the lifecycle prevented it. On a page load it was a 500, and if the trace names had differed the loser would have overwritten the winner's summary and steps |
| Resume or reject a closed run through the loop directly | Threw `LogicException` | Already safe; now goes through `RunState` |

## 4. The concurrent-close race: what I chose

**A per-run lock, a re-read under it, and the transition check, all before anything happens.** `AgentRunner::exclusively()`:

1. Takes `Cache::lock("agent-run:{run_id}")` **without waiting**. The lock lasts as long as the longest request may (runtime budget + 2 × LLM timeout + 10 s, the same figure as the controller's time limit). If another request holds it, the call throws `IllegalRunTransition::lostRace` straight away.
2. Re-reads the row. If its status or segment differs from the copy the caller was working from, the call throws `lostRace`. This catches a request that read the run before another request moved it, even when they do not overlap in time.
3. Runs `RunState::check()` against the fresh row. Then, and only then, the token is consumed, the model is called and a trace is written.

What each loser sees:
- **Lazy expiry on a page load:** the exception is caught and logged as `Log::warning('Lazy expiry lost a race: …')`, and the page shows the run as the winner left it. A page view never turns into a 500.
- **Officer's decision:** the controller catches it, logs `Agent decision refused: …`, and shows the existing "already used, nothing changed" banner.

As a second line of defence, `AgentRunStore::save()` refuses to write over a closed row whoever calls it, so even a lock that expired could not make one close overwrite another.

I chose the lock over a compare-and-set update alone. A compare-and-set only fails at save time, after the loser has already written its trace and possibly called the model. The brief's tests require that a refused move leave no new trace file. The lock uses the cache's lock support, the same mechanism `PendingCallStore` uses. In production the cache store is `database`, and its `cache_locks` table already exists (checked).

One side effect: a page load during an officer's in-progress approval now logs a warning. Before, it got `ALREADY_USED` silently. This is the visibility the brief asked for, at warning level.

## 5. Tests

`RunStateTest` checks every refused move with `assertRefused()`. It asserts that `IllegalRunTransition` was thrown and that nothing changed: the model call count, the visit count, the `tool_approvals` count, the list of trace files, and the stored row (every column except `updated_at`).

| # | Test | Routes covered |
|---|---|---|
| 1 | A closed run cannot be resumed | runner, loop, store (the hole) |
| 2 | A closed run cannot be rejected again | runner, loop |
| 3 | A run awaiting approval cannot be approved twice | stale copy and fresh copy |
| 4 | A run not awaiting approval cannot be approved | runner; in memory RUNNING → RUNNING |
| 5 | A run not awaiting approval cannot be rejected | runner; in memory RUNNING → REJECTED |
| 6 | A `GUARD_REFUSED` run cannot be restarted or continued | approve, reject, `begin()`, loop resume, new run saved under the same id |
| + | A waiting run takes exactly one decision at the store | AWAITING → AWAITING |
| + | The legal path: submitted → suspended → approved → resumed → closed | state checked at every step in memory, and in the table during each segment (no row during the first segment; `AWAITING_APPROVAL` during the resume) |
| + | The race | lock held: lazy expiry logs and changes nothing, decision throws. Lock released: closes once. Stale second page load: logs and changes nothing |
| + | Browser double submit | second POST shows the `ALREADY_USED` banner; one visit, one approval |
| + | Document | every illegal pair has a reason; every stop reason maps to a state; every `agent_runs` column is documented (read from the schema, so a new column fails this test until it is documented); the command prints every section; `--json` matches `RunState::illegal()` |

## 6. Where the brief was wrong, or where I deviated

1. **"RUNNING — no run should be found in this state between requests unless something crashed."** No run is ever found in RUNNING, crash or not, because RUNNING is never stored. See section 2.1.
2. **Test 3 contradicts a Week 5 test.** `AgentResumeTest::test_a_used_token_cannot_be_decided_twice` asserted that a second decision returns `ALREADY_USED`. The brief requires it to throw. I changed the test to expect `IllegalRunTransition` and kept its "one visit, one approval" assertions. The browser behaviour is unchanged, because the controller turns the exception back into the same banner.
3. **Two Week 5 unit tests stopped runs that had never started.** `test_active_seconds_pause_while_waiting…` went straight from construction to AWAITING_APPROVAL, and `test_a_trace_is_never_overwritten` went straight to GOAL_REACHED. Both are now illegal (`— → AWAITING_APPROVAL`, and `— → CLOSED` other than as a refusal). Each test gains one line, `$run->begin()`. Their assertions are unchanged.
4. **"Call it from … the loop, when it sets a stop reason."** The check lives one level down, in `AgentRun::stop()`, which the loop's `finish()` calls. Every stop goes through it, including any future caller that is not the loop.
5. **"Write little new code."** About 230 lines of application code, half of it the document text. The lock is the only real mechanism added.
6. **"AWAITING_APPROVAL → AWAITING_APPROVAL is illegal."** True in memory. In the table, though, a run can go from waiting to waiting legitimately: approved, resumed, then proposes a second write. The row goes AWAITING (segment 1) → AWAITING (segment 2) through an in-memory RUNNING that is never stored. So at the store the rule is "exactly one segment further per decision", not "never waiting to waiting".

## 7. The generated state model document

The output of `php artisan agrivisit:state-model`, unedited (also at `evidence/week6-state-model.md`):

## AgriVisit Agent Run State Model

Generated by `php artisan agrivisit:state-model` from `RunState` and the `agent_runs` table. Do not edit by hand.

### States

| State | What is true while it holds |
|---|---|
| `RUNNING` | A request is executing iterations. Held in memory only: the agent_runs row is written when the run stops or suspends, so no row is ever found in this state. A crash mid-resume leaves the row as it was before the resume (AWAITING_APPROVAL, its token already consumed). |
| `AWAITING_APPROVAL` | A write tool returned APPROVAL_REQUIRED. A pending call exists, an approval token is live in the Week 4 store, nothing has been written, and the conversation ends with the assistant turn carrying that tool_use. |
| `CLOSED` | Terminal. A stop reason other than AWAITING_APPROVAL is set, a trace has been written, and no further model call will be made for this run. |

`—` means no state: the run has not started, or has no row yet.

### Transitions

| From | To | Trigger | Condition |
|---|---|---|---|
| `—` | `RUNNING` | A goal is submitted and passes the restricted-topic guard. | The goal becomes the first user turn (AgentRun::begin). |
| `—` | `CLOSED` | The guard refuses the goal. | Stop reason GUARD_REFUSED only. No model call; zero iterations. |
| `RUNNING` | `AWAITING_APPROVAL` | A write tool returns APPROVAL_REQUIRED. | The pending call is recorded on the run and held in the Week 4 approval flow. |
| `RUNNING` | `CLOSED` | Any other stop. | Stop reason one of GOAL_REACHED, ITERATION_CAP, NO_PROGRESS, REPEATED_FAILURE, RUNTIME_BUDGET, GUARD_REFUSED, MODEL_ERROR. Not REJECTED: there is no held call to reject. |
| `AWAITING_APPROVAL` | `RUNNING` | The officer approves within the window. | The held token is this run's and is consumed by the decision. A new segment opens; budgets carry over. |
| `AWAITING_APPROVAL` | `CLOSED` | The officer rejects, or the approval expires or is no longer on file. | Stop reason REJECTED only. The model is not called again. |

### Illegal moves

Every pair not in the table above. Each throws `IllegalRunTransition`; nothing about the run changes.

| Move | Why it is forbidden |
|---|---|
| `— → AWAITING_APPROVAL` | A run can only propose a write by running. Nothing is held for a run that never called a tool. |
| `RUNNING → RUNNING` | A resumed run continues; it does not begin again. Starting a run that is already running would reset nothing and run it twice. |
| `AWAITING_APPROVAL → AWAITING_APPROVAL` | One decision per held call. A run waiting on a call cannot be suspended again on top of it. |
| `CLOSED → RUNNING` | Closed is terminal. A closed run is never resumed: a rejection is final and a refusal ends the run. |
| `CLOSED → AWAITING_APPROVAL` | Closed is terminal. A closed run cannot hold a new call for approval. |
| `CLOSED → CLOSED` | Closed is terminal. Closing it again would overwrite how it ended (a second rejection, or a lazy expiry racing a decision). |

### Enforcement

- AgentRun::begin(), stop() and beginSegment() check every move in memory, before anything on the run changes.
- AgentRunStore::save() checks every move of the stored row: a closed row is never written again, and a waiting row takes exactly one decision (the next segment).
- AgentRunner::decide() and closeIfLapsed() take a lock on the run and re-read it before moving it, so of two requests moving the same run one wins and the other throws IllegalRunTransition before it consumes a token, calls the model or writes a trace. The lazy expiry logs its loss as a warning; a refused officer decision is logged and shown as already decided.

### Where state lives

Table `agent_runs`, one row per run.

| Column | Meaning |
|---|---|
| `id` | Row key. Not used to identify a run. |
| `run_id` | The run's identity (unique). Names its trace files and appears in tool_approvals.session_id. |
| `goal` | The officer's goal, as submitted. |
| `officer_id` | The configured officer the run was started for. |
| `status` | The state, as 'running' or a stop reason. AWAITING_APPROVAL means waiting; every other stop reason means closed. Never stored as 'running'. |
| `stop_reason` | The stop reason, or null while running. Equal to status once stopped. |
| `prompt_version` | The agent prompt version the run was started with; a resume keeps it. |
| `model` | The model the run was started with; a resume keeps it. |
| `iterations` | Model calls so far, across segments. Counted against max_iterations. |
| `consecutive_failures` | Tool failures in a row; a success resets it. |
| `limits` | The run's own bounds, fixed at start, so a resume uses them and not today's config. |
| `messages` | The conversation exactly as sent and received. A waiting run ends with the assistant turn carrying the held tool_use. |
| `steps` | One entry per iteration, plus the officer's decision step on a resume or rejection. |
| `signatures` | Call signatures already made, for the no-progress check. |
| `pending` | The held call (tool, arguments, tool_use_id, summary) while waiting; null otherwise. |
| `system_prompt` | The exact system prompt, so a resume sends the same one. |
| `declarations` | The exact tool declarations, so a resume sends the same ones. |
| `summary` | How the run stopped, in words. An expiry starts with "The approval ". |
| `input_tokens` | Input tokens across all steps. |
| `output_tokens` | Output tokens across all steps. |
| `active_seconds` | Model and tool time across segments. Time waiting for the officer is not counted. |
| `segment` | Which part of the run was saved last: 1, then 2 after the first decision. Each decision on a held call moves it on by exactly one. |
| `resumed_from` | The previous segment's trace file, from segment 2 on. |
| `trace_files` | Names of the trace files written for this run, oldest first. Pointers to the record, not state. |
| `approval` | The Week 4 approval panel data (token, summary, preview, expiry) while waiting; null once decided. |
| `started_at` | When the run started. |
| `ended_at` | When the last segment stopped. |
| `created_at` | Laravel bookkeeping. |
| `updated_at` | Laravel bookkeeping. |

### What is not state

- Trace files, under `storage/app/agent-runs`.
- The agent_runs table is the state. The trace files are the record: one JSON file per segment ({run_id}.json, then {run_id}.part2.json), written once when a segment stops and never rewritten. They hold much of the same data, but nothing reads a trace back to decide what a run does next; a resume restores from the table. A deleted trace changes no run, and an edited one changes nothing but the evidence.

