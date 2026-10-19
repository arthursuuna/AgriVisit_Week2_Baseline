# Week 6 Phase 1 — the state model, written down and enforced

The agent already has state. A run starts, suspends for an officer, resumes,
closes. It persists in `agent_runs` and it works. But the rules for that lifecycle
exist only as the order the code happens to call things in — nowhere is it
written which states exist, which moves between them are legal, and what is true
in each one.

This phase writes it down, and then makes the code refuse the moves that were
never meant to be possible.

After this phase:

> Every legal transition of a run is named, every illegal one throws, and the
> document describing them is generated from the code rather than maintained
> beside it.

Write little new code. This is mostly making an existing shape explicit.

---

## 1. Scope

| Part | What |
|---|---|
| A | Name the states and transitions in one place |
| B | Enforce them — illegal transitions throw rather than silently working |
| C | Generate the state model document from the code |
| D | Tests, one per illegal transition |

Do not touch `app/Services/Agent/BoundedAgentLoop.php`'s decision logic, the
tools, the guard, or the approval flow. This phase constrains when a run may
change state; it does not change what the agent does.

---

## 2. Part A — name the states

Create `app/Services/AgentTask/RunState.php` holding the states a run can be in.
Derive them from what the system already does rather than inventing new ones.

As far as the current code goes, a run is in one of these:

| State | What is true while it holds |
|---|---|
| `RUNNING` | A request is executing iterations. Transient — no run should be found in this state between requests unless something crashed. |
| `AWAITING_APPROVAL` | A write tool returned `APPROVAL_REQUIRED`. A pending call exists, an approval token is live, nothing has been written, and the conversation ends with the assistant turn carrying that `tool_use`. |
| `CLOSED` | Terminal. A stop reason is set, a trace has been written, and no further model call will be made for this run. |

If the code implies a state this list does not cover, add it and say why in your
report. If one of these turns out not to exist in practice, say that instead of
keeping it for neatness.

### The transitions

| From | To | Trigger | Notes |
|---|---|---|---|
| — | `RUNNING` | a goal is submitted and passes the guard | |
| — | `CLOSED` | the goal is refused by the guard | no model call; this is the zero-iteration path |
| `RUNNING` | `AWAITING_APPROVAL` | a write tool returns `APPROVAL_REQUIRED` | |
| `RUNNING` | `CLOSED` | any other stop reason | |
| `AWAITING_APPROVAL` | `RUNNING` | the officer approves, within the window | |
| `AWAITING_APPROVAL` | `CLOSED` | the officer rejects, or the approval expires or is no longer on file | |

Everything else is illegal. In particular:

- `CLOSED` → anything. A closed run is finished. This is what makes "a rejection
  is final" and "a refusal ends the run" true rather than merely intended.
- `AWAITING_APPROVAL` → `AWAITING_APPROVAL`. One decision per held call.
- `RUNNING` → `RUNNING` as a fresh start. A resumed run continues; it does not
  begin again.

---

## 3. Part B — enforce them

Add a single place that answers "may this run move from X to Y?", and route every
state change through it. Where a transition is refused, throw rather than
returning false quietly — a caller that attempts an illegal move has a bug, and
swallowing it hides the bug.

Call it from at least:

- the loop, when it sets a stop reason
- `AgentRunner`, when it resumes after an approval
- `AgentRunner`, when it closes on a rejection, an expiry, or a missing token
- the lazy expiry check added in Week 5 (a page load closing an abandoned run)

**Watch for this one.** Week 5's expiry-on-read closes a run when someone opens
the run list. If two pages load at once, or a page load races an officer's
decision, two closes can be attempted. One of them must lose, and it must lose
loudly enough to be visible in a log rather than quietly overwriting the other.
Decide how you handle it and say which you chose.

---

## 4. Part C — generate the document

`php artisan agrivisit:state-model [--json]`.

Generate it from `RunState` and the transition table in code — the same rule as
the Task Contract. There is no second copy, so the document cannot describe a
lifecycle the system no longer has.

It must contain:

| Section | Content |
|---|---|
| States | Each state, and what is true while a run is in it |
| Transitions | From, to, what triggers it, and any condition |
| Illegal moves | Every pair not in the table, and why each is forbidden |
| Where state lives | The `agent_runs` columns, and what each one means |
| What is not state | The trace files — written once, never read back as working state |

That last row matters. The traces and the table hold overlapping data and it
would be easy for a reader to assume either could drive the system. Say plainly
that the table is the state and the traces are the record.

---

## 5. Part D — tests

In `tests/Feature/RunStateTest.php`, one test per illegal transition. Each must
assert the exception, and that nothing changed: no model call, no write, no new
trace file.

At minimum:

1. A closed run cannot be resumed.
2. A closed run cannot be rejected again.
3. A run awaiting approval cannot be approved twice.
4. A run that is not awaiting approval cannot be approved.
5. A run that is not awaiting approval cannot be rejected.
6. A `GUARD_REFUSED` run cannot be restarted or continued.

Add one test that walks the full legal path — submitted, suspended, approved,
resumed, closed — and asserts the state at each step.

If any of these currently **succeed** when they should fail, that is the finding
of the phase. Report it before fixing it, with what the consequence would have
been.

---

## 6. Design rules

1. **The state model is generated.** From code, never hand-maintained.
2. **Illegal transitions throw.** Loudly. A silent false hides a bug.
3. **Closed is terminal.** Nothing continues a closed run, by any route.
4. **The table is state; traces are evidence.** A trace is never read back to
   decide what happens next.
5. **No behaviour change.** The agent does what it did before. This phase only
   constrains when a run may change state.

---

## 7. Report back

- The states and transitions as you found them, and anything in the code that
  did not fit the table above.
- Any illegal transition that currently succeeds, and what it would have allowed.
- How you handled the concurrent-close race.
- The generated state model document, in full.
- Where this brief was wrong. The deviation sections in Weeks 5's reports were
  consistently the most useful part of them.
