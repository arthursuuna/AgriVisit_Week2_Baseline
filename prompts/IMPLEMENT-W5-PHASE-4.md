# Week 5 Phase 4 — evidence

The agent works. Phases 1 to 3 built it and four real runs exercised it. This
phase turns those runs into the evidence the week is assessed on, and gets the
work into the repository.

Write almost no new code. If you find yourself building a feature, stop and say
so instead.

---

## 1. Commit first

Three phases are reported as "nothing has been committed". That is the largest
risk in the project right now: a week of work exists only in a working directory.

Commit before anything else, in a sequence that reads as the week's history
rather than one lump:

1. Phase 1 — Claude client and the bounded loop
2. Phase 2 — `search_guidance`, the agent prompt, the Task Contract, the command
3. Phase 3 — terminal refusals and the narrowed guard
4. Phase 3 — persistence, resume, the browser workflow
5. The UI corrections

Check before you commit that no key reaches the repository: `.env` ignored,
`.env.example` carrying placeholders only, no key in any file under `prompts/`,
`evidence/`, or the agent prompts. Say in your report that you checked.

Do not push unless the repository is already set up to push.

---

## 2. The execution traces document

`evidence/week5-execution-traces.md`. This is a Week 5 deliverable, so it is
written for a marker who has not seen the system.

Build it **from the trace JSON files**, not from memory or from the earlier
results documents. If a number in a results file disagrees with a trace, the
trace is right and the disagreement is worth a line.

Open with a short paragraph: what a trace is, where they are written, that each
file is written once and never rewritten, and that a resumed run writes a second
file naming the first.

Then one section per run, in this order:

| Run | Trace | What it demonstrates |
|---|---|---|
| 1 | `20261012-000641-f7zah6` + `.part2` | The whole workflow across a real approval |
| 2 | `20261011-202915-rnwqi2` + `.part2` | A tool failed, the agent adapted without fabricating, the officer declined |
| 3 | `20261011-202958-rytowp` | A limit stopped the run with partial work kept |
| 4 | `20261011-202946-vm6gt2` | A refused topic, nothing run at all |

For each: the goal, the stop reason, iterations against the cap, tokens, active
seconds, and a table of every step — number, plan, tool, arguments, outcome.
Then two or three sentences saying what the run proves, in plain English.

Three specifics that must appear, because they are the strongest things the week
produced:

- **Run 1** — the row counts: visits before the run, while waiting, after
  approval. And the citation check: every `chunk_ref` in the final answer
  against the passages `search_guidance` actually returned, listed, with the
  statement that none was invented.
- **Run 2** — quote the agent's plan after the HTTP 429 verbatim, and the line
  from run 1's answer about insecticide: *"The guidance does mention insecticide
  for termites, but I have no dose guidance and can't give one."* Say what each
  shows: adaptation without fabrication, and three layers of the dosing boundary
  visible at once.
- **Run 4** — zero iterations, zero tokens, no model call, and that the page
  offers no retry.

---

## 3. The boundary evidence document

`evidence/week5-boundaries.md`. Shorter, and about what the system refuses.

**Part A — the refusal that was defeated.** The Phase 2 run
`20261011-192638-wwnjnp`, where the agent was refused and reworded around it.
Give the two plans verbatim. State plainly that the Week 1 boundary matrix was
not holding for an agent, why the no-progress check could not catch it
(signatures are syntactic, the reroute was semantic), and that the only reason
the run was harmless is that the refusal was a false positive.

Then the fix: a guard refusal now ends the run wherever it fires, and the
clinical rule was narrowed afterwards rather than instead.

Present this as a finding, not a defect that was tidied away. It is the best
piece of engineering judgement in the project.

**Part B — the guard, both directions.** The table of queries that must now pass
and queries that must still refuse, with the result of each. Note that no
existing guard test was edited, and that three phrases were broadened in the
same change that narrowed four.

**Part C — the approval boundary.** Row counts from runs 1 and 2, the fact that
an approved call is still schema-validated, and that the loop never grants
approval.

---

## 4. Verify the evidence before you describe it

Do not take the trace files on trust.

1. Every trace named above exists and parses.
2. Each `.part2.json` names its predecessor in `resumed_from` and carries the
   same `run_id`.
3. A second write to an existing trace path fails.
4. Every stop reason in the Task Contract is represented by at least one real
   trace, or is listed as not yet produced. Say which, if any, are missing.
5. The numbers in your documents match the trace files exactly.

Report anything that does not hold rather than correcting it quietly.

---

## 5. Regenerate

- `php artisan agrivisit:tools` — paste the output into the report.
- `php artisan agrivisit:agent-contract` — paste the full contract.
- `php artisan test` — the final count.

---

## 6. Handoff for the deliverables

End your report with a block I can use directly. Keep it factual:

- Test count and assertion count.
- Files added and changed across all four phases, by phase.
- The four traces: run id, goal, stop reason, iterations, tokens in/out, active
  seconds.
- Total tokens and approximate cost across all live runs this week.
- Row counts: visits and `tool_approvals` at the start and end of the week.
- The corpus numbers still in force: chunk count, embedding model, threshold.
- Every configuration value the Week 5 work introduced, with its current setting.
- Anything a reader of the deliverables would be told that is not true today.

That last item matters most. If a document would claim something the code does
not do, say so now rather than letting me write it.

---

## 7. Do not

- Change the agent prompt. It stays at `v1.0` for the week. The v1.1 candidates
  are recorded and deferred.
- Add features, polish UI, or refactor.
- Re-run anything that costs API quota unless a trace is genuinely missing.
- Edit a trace file. Ever.
