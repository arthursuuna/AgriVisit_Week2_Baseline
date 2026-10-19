# Week 6 Phase 3 — governing what the system remembers

Phase 2 gave the system a memory and made every use of it citable. This phase
decides who may read it, how long it is kept, how it is deleted, and shows the
officer what it holds about a farm.

It also closes two things the live run exposed.

After this phase:

> An officer can see everything the system remembers about a farm, understand
> why each entry is held, and delete any of it. The rules for who reads memory
> and how long it lives are written down and enforced, not assumed.

---

## 1. Scope

| Part | What |
|---|---|
| A | The two fixes the Phase 2 live run exposed |
| B | Who may read memory |
| C | Retention — and why a stale memory is a correctness problem, not a storage one |
| D | Deletion, and what it deliberately does not delete |
| E | The officer's memory view |
| F | The Memory Design and Data Handling Note |

Do not change the loop, the guard, the approval flow, `RunState`, or the write
path. The recorder stays the only writer.

---

## 2. Part A — what the live run exposed

### A1. The citation is one step above the proposal

Run 2 cited M-0001 in the step that searched guidance, then proposed 2026-11-12
in the next step with no reference. The run suspends on the proposal, so there is
no closing answer to carry the citation — the approval panel *is* the ending.

The officer deciding on that visit sees the panel. The reason the date moved is
one step above it.

**Fix it in the panel, not the prompt.** Put the proposing step's plan on the
approval panel, under the proposed action, labelled as the assistant's reasoning.
A prompt instruction asking the model to repeat the citation is a request; a
panel that shows the reasoning is a guarantee.

### A2. Two dates, one field

The model wrote *"declined a visit today (2026-10-26)"*. The decision was made on
2026-10-19; 2026-10-26 was the *proposed* date. The data was right and the
sentence was wrong, because an entry exposes one date and buries the other in
prose.

Add `proposed` to the recall output — the date from the held call's arguments,
where the tool has one. `occurred_at` is when the officer decided; `proposed` is
what they decided about. Two facts, two fields.

This widens what memory exposes, so widen it precisely: the date only, not the
whole argument set. The purpose text is model-composed and replaying it across
runs is the quieter injection channel the Phase 2 report identified.

---

## 3. Part B — who may read memory

Three questions, and the answers have to be decisions rather than defaults.

### Can one officer see another's decisions about a farm?

**Yes, and say why.** A farm's history belongs to the farm, not to whoever
happened to visit it. An extension officer covering for a colleague needs to know
the farmer is away until November. Scoping memory per officer would make it
useless exactly when it matters most.

Record the deciding officer on every entry and show it, so the officer reading it
knows whose decision it was.

### Can the Week 4 tool console read memory?

Today, yes — the tool is in the global registry, so the Gemini console offers it.
That console's prompt has no memory citation rule, so memory can be read there
with none of this week's discipline.

**Remove `recall_farm_history` from the console's declarations.** The console's
toolset is built separately from the agent's, so this should be small. If it
turns out to need a per-surface allow-list rather than a one-line change, stop
and say so — then leave it enabled, document it, and let Week 7 decide.

Keep it callable by hand on the console. A person reading the memory directly is
fine; a model reading it without the citation rule is the problem.

### Does the model ever see memory it did not ask for?

It must not. Memory reaches the model only as the result of a
`recall_farm_history` call it chose to make. Nothing injects it into the system
prompt, the goal, or any other tool's output. Add a test that asserts this —
grep the built request for a `memory_ref` on a run where the tool was never
called.

---

## 4. Part C — retention

Pick a period and defend it. The defence is not about storage.

**A stale memory is worse than no memory**, because the agent cites it with the
same confidence as a fresh one. "The farmer is away until November" is useful for
a month and misleading after a year — and an agent quoting it in 2027 would be
accurate about the record and wrong about the world.

So retention is a correctness control. Suggested: **12 months**, configurable,
with the reasoning stated. Entries past it are not returned by
`recall_farm_history` and are eligible for deletion.

Implement the read filter now — an entry older than the window is simply not
returned. Do not build a scheduled purge; note in the Note that deletion of
expired entries is manual until there is a scheduler, and that an unreturned
entry still exists on disk.

Be honest about that gap rather than describing a purge that does not run.

---

## 5. Part D — deletion, and what it does not touch

The officer can delete any entry for a farm.

**What deletion removes:** the entry. It will not be returned by any future
recall.

**What it does not remove, deliberately:** the execution traces of runs that
already cited it. Those are written once and never rewritten — that rule has been
load-bearing since Week 5 and it is what makes a trace evidence rather than a
document. A trace that cites M-0001 keeps citing it after M-0001 is deleted.

State this plainly in the Note, because it is a real tension and the resolution
is a choice:

> Memory is deletable; the record of what the agent was told at the time is not.
> A deleted memory stops influencing future runs. It does not rewrite the history
> of runs that already used it.

Record the deletion itself: who deleted it, when, and the `memory_ref`. A
deletion is an officer action on the system and belongs in the audit the same way
an approval does.

---

## 6. Part E — the officer's memory view

A page at `/farms/{farm_id}/memory`, or wherever fits the existing routes.

For each entry, newest first:

| Shown | Why |
|---|---|
| `memory_ref` | The handle the agent cites — the officer should recognise it |
| What was decided, in words | The summary |
| The proposed date, and the date decided | Part A2, as two fields |
| The officer's own reason, verbatim | Their words, unedited |
| Which officer decided | Part B |
| The run it came from, linked | Provenance — from this entry back to the full trace |
| A Delete control | Part D |

Above the list, one sentence saying what this page is: *what the assistant can
recall about this farm, and nothing else.* An officer should be able to read this
page and know exactly what the agent knows.

Link to it from the run page wherever a `recall_farm_history` step appears.

---

## 7. Part F — the Memory Design and Data Handling Note

`evidence/week6-memory-design.md`. This is a Week 6 deliverable, written for
someone who has not seen the system.

Generate what can be generated — the schema from the table, the retention period
from config, the tool's schema from the registry — and write the rest. Say at the
top which parts are generated.

| Section | Content |
|---|---|
| What is stored | Every field, and **why that field earns its place**. A field with no justification comes out instead of being documented. |
| What is deliberately not stored | Preferences, the model's reasoning, model-composed argument text. With the reason: a decision is a fact the system observed; a preference is an inference nobody authorised it to make. |
| Who writes it | The application, on an officer's decision. No tool writes memory, and a test enforces that. |
| Who reads it | The agent, through one tool, by its own choice. The officer, through the memory view. Not the Week 4 console's model (Part B). |
| How it reaches the model | As a tool result the model asked for. Never injected. |
| Retention | The period, and the correctness argument for it. Including that expired entries are filtered from reads but not yet purged. |
| Deletion | What it removes, what it does not, and why that is the right trade. |
| How use is made visible | `memory_ref` citations, the citation check, and the recall step on the run page. |
| What is not guaranteed | That the agent never let an uncited memory shape a decision. Nothing can prove that. The guarantee is that a cited memory is real and an uncited claim about the past is unsupported. |
| Known gaps | No authentication behind `officer_id`. Decisions made on the Week 4 console are not remembered. No scheduled purge. The injection surface from Phase 2 §8. |

That last row is the one a marker will look for. The Phase 2 report wrote most of
it already — carry it across rather than softening it.

---

## 8. Tests

1. An entry older than the retention window is not returned by
   `recall_farm_history`, and still exists in the table.
2. Deleting an entry removes it from recall, leaves every trace file byte-identical,
   and writes a deletion record.
3. A run where `recall_farm_history` was never called contains no `memory_ref`
   anywhere in the request sent to the model.
4. The memory view shows every entry for a farm and nothing for another farm.
5. The approval panel carries the proposing step's plan.
6. `proposed` and `occurred_at` are separate fields and carry different dates in
   the Phase 2 scenario.
7. The Week 4 console's declarations do not include `recall_farm_history`, while
   the agent's do.

---

## 9. Design rules

1. **The officer can see everything the agent can recall**, on one page.
2. **A farm's history belongs to the farm**, not to one officer.
3. **Memory reaches the model only when it asks.** Never injected.
4. **Stale memory is a correctness risk**, and retention is how it is managed.
5. **Memory is deletable; traces are not.**
6. **A documented gap beats an undocumented feature.** Where something is not
   implemented, the Note says so.

---

## 10. Report back

- The retention period you chose and the argument for it, in your words.
- Whether removing the tool from the console was a one-line change or needed an
  allow-list.
- The Note, in full.
- A screenshot path or description of the memory view, so it can go in the
  deliverable.
- What this brief got wrong. Two for two so far.
