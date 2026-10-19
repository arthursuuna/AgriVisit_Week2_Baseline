# Week 6 Phase 4 — the interface specification, and the week's evidence

Three of the week's deliverables exist. This phase produces the fourth and
gathers what the documents need.

Write almost no application code. If you find yourself building a feature, stop
and say so.

**Do not commit anything.** Not a single `git commit`, `git add` or `git push`.
List what should be committed and in what order; George commits.

---

## 1. Scope

| Part | What |
|---|---|
| A | The MCP-style interface specification |
| B | Regenerate what has drifted |
| C | Handoff for the deliverables |

---

## 2. Part A — one capability, specified as an interface

The brief asks for *one project capability documented as an MCP-style interface:
capability, inputs, outputs, permissions and security boundary.*

**The capability to specify is the guidance corpus** — `search_guidance`. It is
the only thing in this system with value to a caller outside it: a curated,
provenance-tracked Ugandan agronomy corpus with restricted content removed at
ingestion, retrieval at a calibrated threshold, and a citable reference on every
passage. Another extension service's software could want exactly that.

Nothing else here is worth exposing, and saying why is half the deliverable.

Generate it where you can — the schemas from `RecallFarmHistoryTool`'s sibling
`SearchGuidanceTool`, the threshold and corpus size from config and the index —
so the specification cannot describe an interface the system does not have. Same
rule as the Task Contract, the state model and the Note.

`php artisan agrivisit:mcp-spec [--write]` → `evidence/week6-mcp-interface.md`.

### What it must contain

| Section | Content |
|---|---|
| **Capability** | What the server does, in two sentences a stranger understands. What it is *for*. |
| **Inputs** | The tool's real input schema. What each field means and what is rejected. |
| **Outputs** | The real output schema. What `count: 0` means, and that it is a success. |
| **Permissions** | Who may call it, how a caller is identified, what a caller may not do. Be honest: there is no authentication today. Say what would be needed. |
| **Security boundary** | Below. |
| **What is deliberately not exposed** | Below. |
| **Obligations that transfer to the caller** | Below. |
| **Errors** | The result codes a caller can receive and what each means. |

### The security boundary

State precisely what the server guarantees regardless of who calls it, and what
it does not:

**Guaranteed, because it is enforced in code:**
- Every query is screened by the restricted-topic guard before retrieval. An
  external caller's query is screened exactly as the agent's is. A refused query
  returns `GUARD_REFUSED` and retrieves nothing.
- No passage is returned below the similarity threshold. There is no fallback to
  best-available results.
- The corpus itself has had restricted content removed at ingestion, so a
  passage cannot contain a dosing instruction even if a query asks for one.
- The interface is read-only. There is no path from it to a write.

**Not guaranteed:**
- That the caller cites what it is given, or uses it at all (see obligations).
- That the corpus is current. State when it was last built.
- Anything about who the caller is. There is no authentication.

### What is deliberately not exposed, and why

This is the part worth writing carefully.

| Not exposed | Why |
|---|---|
| `schedule_visit`, `log_followup` | They write. Every write in this system is held for a named human who sees what will change. An interface cannot carry that boundary — approval is a person deciding, not a permission a caller holds. |
| `recall_farm_history` | Memory is scoped to an officer's own decisions about farms they work with. Exposing it would publish one organisation's operational history. |
| `farm_profile_lookup` | Farm records are the deployment's own data, synthetic here and real elsewhere. |
| The agent loop | A bounded loop with a human approval boundary is not a capability a caller can hold; it is a workflow the deployment owns. Exposing it would hand out the agency this project spent five weeks bounding. |

That last row is the argument, not a formality. The one capability worth sharing
is the grounded knowledge. The judgement and the authority stay here.

### Obligations that transfer to the caller

Every passage carries a `chunk_ref`. The server can return them; it cannot make
the caller cite them.

So this interface hands the caller an obligation along with the data: a system
that uses these passages and does not cite them has broken the grounding
discipline the corpus exists to support, and the server has no way to tell. Say
that plainly, and say that it is the same limit the Note states about the agent's
own citations.

---

## 3. Part B — regenerate what has drifted

- `php artisan agrivisit:tools` — the catalogue is now five tools.
- `php artisan agrivisit:agent-contract` — includes `recall_farm_history` and
  prompt v1.1.
- `php artisan agrivisit:state-model`
- `php artisan agrivisit:memory-note`
- `php artisan test` — the final count.

Paste each output in your report.

Note for the record: the Week 4 Tool Catalogue and Tool Demonstration documents
live outside this repository and still describe three tools. They need updating
by hand; the current catalogue output is what to update them from.

---

## 4. Part C — handoff

End your report with a block I can use directly.

- Test count and assertion count.
- Files added and changed this week, by phase.
- **What should be committed, in what order, as commit messages** — but do not
  commit.
- The three live runs: run id, goal, what memory did, stop reason, tokens.
- Row counts at the end of the week: `visits`, `tool_approvals`, `agent_runs`,
  `farm_memories`, `farm_memory_deletions`.
- Every configuration value Week 6 introduced, with its setting.
- The five tools, with access and approval.
- **Anything a reader of the Week 6 deliverables would be told that is not true
  today.** The Week 5 version of this list was the most useful thing in that
  report. Include at minimum: the authentication gap, the absent purge, the
  console decisions that are not remembered, the uncited-influence limit, and
  run 3.

---

## 5. Do not

- Commit, stage or push anything.
- Change the loop, the guard, the approval flow, `RunState`, the recorder or the
  prompt.
- Make a live model run unless something is genuinely missing.
- Edit a trace, or a memory entry outside the delete path.

---

## 6. Report back

The specification in full, the regenerated outputs, the handoff block, and where
this brief was wrong. Three phases, three corrections — the A1 fix in Phase 3 was
better than what I asked for, so keep doing that.
