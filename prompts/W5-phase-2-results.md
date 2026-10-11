# Week 5 Phase 2 — Give the Agent a Real Job: Results

**Implemented from:** `prompts/IMPLEMENT-W5-PHASE-2.md`
**Date:** 2026-10-11
**Status:** All four parts complete. **221 tests pass** (1173 assertions). Two live runs against Claude: one `GOAL_REACHED`, one `AWAITING_APPROVAL` with nothing written. Nothing has been committed.

| Part | State |
|---|---|
| A. `search_guidance` tool | Done. Registered, enabled, in `agrivisit:tools`; guard runs on the agent's query |
| B. Agent system prompt | Done. `resources/prompts/agent/v1.0/system.md`, six headings, version in every trace |
| C. Agent Task Contract | Done. `agrivisit:agent-contract [--json]`, generated from config, registry, prompt and code |
| D. `agrivisit:agent` command | Done. `--max-iterations`, `--dry-run`; prints the plan for each step |
| Configuration | `AGENT_PROMPT_VERSION=v1.0`; `ANTHROPIC_FALLBACKS=` (empty) with the brief's comment |
| Tests | 13 new (7 search, 4 contract, 2 command); 6 Week 4 tests updated for a four-tool catalogue |
| Live runs | 2 (section 4) |

## 1. Files

### Added

| File | Purpose |
|---|---|
| `app/Services/Tools/Catalogue/SearchGuidanceTool.php` | Part A. Read tool over the Week 3 corpus; guard on the query, 0.65 threshold, no fallback |
| `resources/prompts/agent/v1.0/system.md` | Part B. Role, Task, Context, Constraints, Output format, Failure behaviour |
| `app/Services/AgentTask/AgentToolset.php` | Enabled non-probe tools → Claude declarations; loads and renders the versioned prompt. Shared by the command and the contract |
| `app/Services/AgentTask/AgentTaskContract.php` | Part C. Builds the contract; stop reasons read by reflection from `StopReason` |
| `app/Services/AgentTask/VersionedAgentTraceWriter.php` | Subclass of the Phase 1 writer; stamps `prompt_version` and `model` on each trace |
| `app/Console/Commands/AgentContract.php` | `agrivisit:agent-contract [--json]` |
| `app/Console/Commands/RunAgent.php` | `agrivisit:agent {goal} {--max-iterations=} {--dry-run}` |
| `tests/Fakes/FakeRetriever.php` | Fixed chunks and scores, no threshold, records queries |
| `tests/Feature/SearchGuidanceToolTest.php` | The brief's 5 tests, plus "threshold does not bend" and the agent-composed restricted query |
| `tests/Feature/AgentContractTest.php` | Every enabled tool and every `StopReason` constant (by reflection) appears; values come from config |
| `tests/Feature/RunAgentCommandTest.php` | Exit 0 and stop reason printed; prompt version in trace; dry run never calls the model |

### Changed

| File | Change |
|---|---|
| `config/agrivisit.php` | `search_guidance` in `tools.enabled`; `agent.prompt_version`; `anthropic.fallbacks` default now empty |
| `app/Providers/AgriVisitServiceProvider.php` | Registers `search_guidance`; binds `AgentToolset` and `AgentTaskContract`; `AgentTraceWriter` → `VersionedAgentTraceWriter`; the loop receives only the three limits |
| `.env`, `.env.example` | `AGENT_PROMPT_VERSION=v1.0`; `ANTHROPIC_FALLBACKS=` with the comment from section 6 |
| `tests/Feature/` ToolCatalogue, ToolSession, FailureEvidence, SchemaTranslator, ClaudeSchemaTranslator tests | Expected tool list 3 → 4 (catalogue-completeness assertions only) |

Not modified: `app/Services/Agent/`, `app/Services/Llm/`, `Retrieval/`, `Corpus/`, `Checklist/`, the checklist prompts, approval and audit code, and the three existing tools.

## 2. Design rules

| Rule | Held | How |
|---|---|---|
| 1. Searching is an action | Yes | Corpus reachable only through `search_guidance` via `ToolInvoker`; every search is a traced, counted step |
| 2. Guard on agent-composed input | Yes | `screenRequest()` on the query inside `execute()`; a test proves the corpus is not even queried. Live run 1 shows it firing (finding 5.1) |
| 3. Finding nothing is a success | Yes | `ok: true, count: 0, results: []`; tested |
| 4. Threshold does not bend | Yes | Filter at the configured 0.65 after retrieval; no fallback; a test raises the threshold and gets 1 result for `max_results: 5` |
| 5. Prompt is a versioned file | Yes | Loaded via `PromptRepository` (`agent`, `v1.0`, `system`); `prompt_version` in every trace |
| 6. Contract is generated | Yes | Config, registry, prompt sections, `StopReason` reflection, `AgentRun` / `AgentStep` trace shape |
| 7. Nothing writes without approval | Yes | Live run 2: visits 7 before, 7 after; stop `AWAITING_APPROVAL` |

## 3. `--dry-run` output

`php artisan agrivisit:agent "Prepare me for a visit to farm UG-KYA-012" --dry-run`

```
Prompt version: agent/v1.0

--- Tool declarations ---
[
    {
        "name": "farm_profile_lookup",
        "description": "Look up a farm by its ID to get its district, crops, last visit date, outstanding issues and previous advice.",
        "input_schema": {
            "type": "object",
            "properties": {
                "farm_id": {
                    "type": "string",
                    "description": "Farm ID in the form UG-XXX-000, e.g. UG-KYA-012.",
                    "pattern": "^UG-[A-Z]{3}-\\d{3}$"
                }
            },
            "required": [
                "farm_id"
            ]
        }
    },
    {
        "name": "schedule_visit",
        "description": "Schedule a field visit to a farm on a given date; the officer must approve before it is booked.",
        "input_schema": {
            "type": "object",
            "properties": {
                "farm_id": {
                    "type": "string",
                    "description": "Farm ID in the form UG-XXX-000, e.g. UG-KYA-012.",
                    "pattern": "^UG-[A-Z]{3}-\\d{3}$"
                },
                "visit_date": {
                    "type": "string",
                    "description": "Visit date as YYYY-MM-DD. Today or later.",
                    "format": "date"
                },
                "purpose": {
                    "type": "string",
                    "description": "Why the visit is needed, in one short sentence.",
                    "minLength": 1,
                    "maxLength": 200
                }
            },
            "required": [
                "farm_id",
                "visit_date",
                "purpose"
            ]
        }
    },
    {
        "name": "log_followup",
        "description": "Record a follow-up note and outcome against an existing visit; the officer must approve before it is saved.",
        "input_schema": {
            "type": "object",
            "properties": {
                "farm_id": {
                    "type": "string",
                    "description": "Farm ID in the form UG-XXX-000. Must be the farm the visit belongs to.",
                    "pattern": "^UG-[A-Z]{3}-\\d{3}$"
                },
                "visit_id": {
                    "type": "string",
                    "description": "Visit ID returned by schedule_visit, e.g. V-0001.",
                    "pattern": "^V-\\d{4,}$"
                },
                "notes": {
                    "type": "string",
                    "description": "What was observed or agreed.",
                    "minLength": 1,
                    "maxLength": 2000
                },
                "outcome": {
                    "type": "string",
                    "description": "Status of the issue after the follow-up.",
                    "enum": [
                        "resolved",
                        "ongoing",
                        "escalated"
                    ]
                }
            },
            "required": [
                "farm_id",
                "visit_id",
                "notes",
                "outcome"
            ]
        }
    },
    {
        "name": "search_guidance",
        "description": "Search the curated agronomy guidance corpus; returns passages above the relevance threshold, each with a chunk_ref to cite, or none.",
        "input_schema": {
            "type": "object",
            "properties": {
                "query": {
                    "type": "string",
                    "description": "What to search the guidance corpus for, in plain words, e.g. \"maize leaf damage causes\".",
                    "minLength": 3,
                    "maxLength": 300
                },
                "max_results": {
                    "type": "integer",
                    "description": "How many passages to return at most. Default 3.",
                    "minimum": 1,
                    "maximum": 5,
                    "default": 3
                }
            },
            "required": [
                "query"
            ],
            "additionalProperties": false
        }
    }
]

--- System prompt ---
## Role

You are an assistant helping a Ugandan agricultural extension officer prepare for and carry out farm visits. The officer is the decision-maker. You propose; you never decide.

## Task

Given the officer's stated goal, use the available tools to gather what is needed and propose the actions that serve it. Take one action per turn. Before each action, state in one or two sentences why you are taking it.

## Context

Farm records, visits and follow-ups exist only behind tools. There is no other way to read or change them, and nothing you state about a farm is true unless a tool returned it. Agronomic guidance exists only in the curated corpus reached through `search_guidance`. The officer's identity is configured by the system; you do not ask who they are. Today's date is 2026-10-11.

## Constraints

- Never state a pesticide, fungicide or herbicide dose, rate or concentration. Never give veterinary or clinical advice. If the goal requires it, say that it is outside what you can help with and stop.
- Every agronomic statement you make must cite the `chunk_ref` of a passage `search_guidance` returned to you. If you have no passage, say you did not find guidance. Do not supply it from your own knowledge.
- Never say an action has been taken. Write actions require the officer's approval, which happens outside this conversation.
- One tool call per turn.

## Output format

Before each tool call: one or two sentences of reasoning. At the end of the run: a short summary of what you found, what you are proposing, and what is waiting on the officer. Plain sentences, no headings.

## Failure behaviour

If a tool fails, say what failed and why in one sentence, then either take a different action or stop and explain what you could not do. Never repeat a call that just failed with the same arguments. Never fill a gap with invented data. If you cannot make progress, say so and stop — stopping with an explanation is a correct outcome.


Dry run: the model was not called.
```

## 4. Live runs

### Run 1 — the brief's goal

`php artisan agrivisit:agent "Prepare me for a visit to farm UG-KYA-012"`

```
Goal: Prepare me for a visit to farm UG-KYA-012

I'll start by looking up the farm's profile to see its crops, last visit, and outstanding issues.
+-----------+---------------------+---------+---------------+
| Iteration | Tool                | Outcome | Duration (ms) |
+-----------+---------------------+---------+---------------+
| 1         | farm_profile_lookup | OK      | 26.11         |
+-----------+---------------------+---------+---------------+
  Arguments: {"farm_id":"UG-KYA-012"}

I'll search the guidance corpus for the unconfirmed maize leaf damage, since that is the main open
issue.
+-----------+-----------------+-------------------+---------------+
| Iteration | Tool            | Outcome           | Duration (ms) |
+-----------+-----------------+-------------------+---------------+
| 2         | search_guidance | INVALID_ARGUMENTS | 2.28          |
+-----------+-----------------+-------------------+---------------+
  Arguments: {"query":"maize leaf damage causes and how to diagnose"}
  Query refused by the restricted-topic guard (clinical): AgriVisit does not diagnose animal or human health conditions or recommend treatment. Refer the officer to a qualified veterinary or medical professional.

The maize search got incorrectly blocked as a clinical query—it's actually a crop topic. I'll
switch to searching for ways to improve bean yields instead.
+-----------+-----------------+---------+---------------+
| Iteration | Tool            | Outcome | Duration (ms) |
+-----------+-----------------+---------+---------------+
| 3         | search_guidance | OK      | 1741.87       |
+-----------+-----------------+---------+---------------+
  Arguments: {"query":"improving bean yields smallholder"}

The bean search worked. I'll try the maize leaf damage question again with different wording, since
the earlier failure was a guard misfire and not a problem with the topic.
+-----------+-----------------+---------+---------------+
| Iteration | Tool            | Outcome | Duration (ms) |
+-----------+-----------------+---------+---------------+
| 4         | search_guidance | OK      | 1076.66       |
+-----------+-----------------+---------+---------------+
  Arguments: {"query":"maize crop pests and diseases scouting leaves"}

Farm UG-KYA-012 is in Kyankwanzi and has two crops. It has 2 acres of Longe 5 maize planted on
2026-03-10 and 1 acre of NABE 15 beans planted on 2026-03-18. The last visit was on 2026-05-22.

**Open issues from the record:**
- **Maize leaf damage:** it was reported at the last visit and the cause was never confirmed.
- **Bean yields:** the farmer asked how to improve them after a poor previous season.
- **Earlier advice:** keep a planting and harvest record book, and check field margins for weeds
before the next planting. You could ask whether either was done.

**Guidance for the maize leaf damage:**
- My first search on this was wrongly blocked by a topic guard, so I reworded it.
- The guidance lists stem borers as a possible cause. They bore into leaves, stems and cobs, and
cause damage, stunted growth and lower yields (DOC-002-C033).
- The listed management steps for stem borers are planting early, uprooting infested plants,
removing stalks after harvest, and rotating crops (DOC-002-C033).
- It also describes cutworms, termites and aphids (DOC-002-C033).
- Nothing I found ties the damage on this farm to any one of these, so the visit needs to check the
plants directly.
- I did not find guidance on telling these causes apart by their leaf symptoms.

**Guidance for the beans:**
- Smallholder bean yields average about 250 kg per acre, against a potential of 700 to 1500 kg per
acre depending on variety (DOC-001-C003).
- To maximise yield, the manual advises soil testing before choosing fertilizer type, rate and
method (DOC-001-C040).
- It also advises rotating crops to reduce pest and disease build-up, and notes that beans do not
grow well in compacted, very alkaline, poorly drained or highly acidic soils (DOC-001-C040).

**Waiting on you:** I have not booked anything. If you want a visit scheduled, give me a date and
I'll propose it for your approval. The purpose would be to confirm the cause of the maize leaf
damage and review bean yield practices.
+-----------+------------+---------+---------------+
| Iteration | Tool       | Outcome | Duration (ms) |
+-----------+------------+---------+---------------+
| 5         | — (answer) | OK      | 0             |
+-----------+------------+---------+---------------+

Summary:
Farm UG-KYA-012 is in Kyankwanzi and has two crops. It has 2 acres of Longe 5 maize planted on
2026-03-10 and 1 acre of NABE 15 beans planted on 2026-03-18. The last visit was on 2026-05-22.

**Open issues from the record:**
- **Maize leaf damage:** it was reported at the last visit and the cause was never confirmed.
- **Bean yields:** the farmer asked how to improve them after a poor previous season.
- **Earlier advice:** keep a planting and harvest record book, and check field margins for weeds
before the next planting. You could ask whether either was done.

**Guidance for the maize leaf damage:**
- My first search on this was wrongly blocked by a topic guard, so I reworded it.
- The guidance lists stem borers as a possible cause. They bore into leaves, stems and cobs, and
cause damage, stunted growth and lower yields (DOC-002-C033).
- The listed management steps for stem borers are planting early, uprooting infested plants,
removing stalks after harvest, and rotating crops (DOC-002-C033).
- It also describes cutworms, termites and aphids (DOC-002-C033).
- Nothing I found ties the damage on this farm to any one of these, so the visit needs to check the
plants directly.
- I did not find guidance on telling these causes apart by their leaf symptoms.

**Guidance for the beans:**
- Smallholder bean yields average about 250 kg per acre, against a potential of 700 to 1500 kg per
acre depending on variety (DOC-001-C003).
- To maximise yield, the manual advises soil testing before choosing fertilizer type, rate and
method (DOC-001-C040).
- It also advises rotating crops to reduce pest and disease build-up, and notes that beans do not
grow well in compacted, very alkaline, poorly drained or highly acidic soils (DOC-001-C040).

**Waiting on you:** I have not booked anything. If you want a visit scheduled, give me a date and
I'll propose it for your approval. The purpose would be to confirm the cause of the maize leaf
damage and review bean yield practices.

+--------------+------------+-------------------+---------+--------------------------------------------------------------------------------------------------------------------------------------------------+
| Stop reason  | Iterations | Tokens (in / out) | Seconds | Trace                                                                                                                                            |
+--------------+------------+-------------------+---------+--------------------------------------------------------------------------------------------------------------------------------------------------+
| GOAL_REACHED | 5 / 6      | 17173 / 1406      | 23.7    | C:\Users\user\OneDrive - African Palliative Care Association\Desktop\AgriVisit_Week2_Baseline\storage\app/agent-runs\20261011-192638-wwnjnp.json |
+--------------+------------+-------------------+---------+--------------------------------------------------------------------------------------------------------------------------------------------------+
```

**Trace** `storage/app/agent-runs/20261011-192638-wwnjnp.json`. The `messages` array and the duplicate `summary` are omitted here; both are in the file. This run predates the limits cleanup, so its `limits` block also shows `trace_path` and `prompt_version`; new traces carry only the three limits.

```json
{
    "run_id": "20261011-192638-wwnjnp",
    "prompt_version": "v1.0",
    "model": "claude-sonnet-5-5",
    "goal": "Prepare me for a visit to farm UG-KYA-012",
    "limits": {
        "max_iterations": 6,
        "max_consecutive_failures": 3,
        "max_runtime_seconds": 120,
        "trace_path": "C:\\Users\\user\\OneDrive - African Palliative Care Association\\Desktop\\AgriVisit_Week2_Baseline\\storage\\app/agent-runs",
        "prompt_version": "v1.0"
    },
    "started_at": "2026-10-11T19:26:38+03:00",
    "ended_at": "2026-10-11T19:27:01+03:00",
    "duration_seconds": 23.633,
    "iterations": 5,
    "consecutive_failures": 0,
    "status": "GOAL_REACHED",
    "stop_reason": "GOAL_REACHED",
    "total_tokens": {
        "input": 17173,
        "output": 1406
    },
    "steps": [
        {
            "iteration": 1,
            "plan": "I'll start by looking up the farm's profile to see its crops, last visit, and outstanding issues.",
            "tool": "farm_profile_lookup",
            "arguments": {
                "farm_id": "UG-KYA-012"
            },
            "outcome": "OK",
            "message": null,
            "duration_ms": 26.11,
            "input_tokens": 1940,
            "output_tokens": 94,
            "tool_calls_proposed": 1
        },
        {
            "iteration": 2,
            "plan": "I'll search the guidance corpus for the unconfirmed maize leaf damage, since that is the main open issue.",
            "tool": "search_guidance",
            "arguments": {
                "query": "maize leaf damage causes and how to diagnose"
            },
            "outcome": "INVALID_ARGUMENTS",
            "message": "Query refused by the restricted-topic guard (clinical): AgriVisit does not diagnose animal or human health conditions or recommend treatment. Refer the officer to a qualified veterinary or medical professional.",
            "duration_ms": 2.28,
            "input_tokens": 2294,
            "output_tokens": 99,
            "tool_calls_proposed": 1
        },
        {
            "iteration": 3,
            "plan": "The maize search got incorrectly blocked as a clinical query—it's actually a crop topic. I'll switch to searching for ways to improve bean yields instead.",
            "tool": "search_guidance",
            "arguments": {
                "query": "improving bean yields smallholder"
            },
            "outcome": "OK",
            "message": null,
            "duration_ms": 1741.87,
            "input_tokens": 2508,
            "output_tokens": 236,
            "tool_calls_proposed": 1
        },
        {
            "iteration": 4,
            "plan": "The bean search worked. I'll try the maize leaf damage question again with different wording, since the earlier failure was a guard misfire and not a problem with the topic.",
            "tool": "search_guidance",
            "arguments": {
                "query": "maize crop pests and diseases scouting leaves"
            },
            "outcome": "OK",
            "message": null,
            "duration_ms": 1076.66,
            "input_tokens": 4671,
            "output_tokens": 187,
            "tool_calls_proposed": 1
        },
        {
            "iteration": 5,
            "plan": "Farm UG-KYA-012 is in Kyankwanzi and has two crops. It has 2 acres of Longe 5 maize planted on 2026-03-10 and 1 acre of NABE 15 beans planted on 2026-03-18. The last visit was on 2026-05-22.\n\n**Open issues from the record:**\n- **Maize leaf damage:** it was reported at the last visit and the cause was never confirmed.\n- **Bean yields:** the farmer asked how to improve them after a poor previous season.\n- **Earlier advice:** keep a planting and harvest record book, and check field margins for weeds before the next planting. You could ask whether either was done.\n\n**Guidance for the maize leaf damage:**\n- My first search on this was wrongly blocked by a topic guard, so I reworded it.\n- The guidance lists stem borers as a possible cause. They bore into leaves, stems and cobs, and cause damage, stunted growth and lower yields (DOC-002-C033).\n- The listed management steps for stem borers are planting early, uprooting infested plants, removing stalks after harvest, and rotating crops (DOC-002-C033).\n- It also describes cutworms, termites and aphids (DOC-002-C033).\n- Nothing I found ties the damage on this farm to any one of these, so the visit needs to check the plants directly.\n- I did not find guidance on telling these causes apart by their leaf symptoms.\n\n**Guidance for the beans:**\n- Smallholder bean yields average about 250 kg per acre, against a potential of 700 to 1500 kg per acre depending on variety (DOC-001-C003).\n- To maximise yield, the manual advises soil testing before choosing fertilizer type, rate and method (DOC-001-C040).\n- It also advises rotating crops to reduce pest and disease build-up, and notes that beans do not grow well in compacted, very alkaline, poorly drained or highly acidic soils (DOC-001-C040).\n\n**Waiting on you:** I have not booked anything. If you want a visit scheduled, give me a date and I'll propose it for your approval. The purpose would be to confirm the cause of the maize leaf damage and review bean yield practices.",
            "tool": null,
            "arguments": [],
            "outcome": "OK",
            "message": null,
            "duration_ms": 0,
            "input_tokens": 5760,
            "output_tokens": 790,
            "tool_calls_proposed": 0
        }
    ],
    "pending": null,
    "signatures": [
        "37bef86eda635bc3cce2d537b11f44441b29b45f",
        "ef3c6f90ba07bbecdc8d37f428b2306440184783",
        "357222f551fc3e2ccff0dcc15a27147edb2d2b52",
        "d63f78bbcf9ef6d859ad3de27860ae51dd0a31c5"
    ]
}
```

**Citation check.** Passages returned by the two successful searches: DOC-001-C002, DOC-001-C040, DOC-001-C003, DOC-002-C034, DOC-002-C033, DOC-002-C005 (scores 0.714 to 0.761). Cited in the final answer: DOC-002-C033, DOC-001-C003, DOC-001-C040. **Every cited `chunk_ref` was returned to the agent in this run; none was invented.**

### Run 2 — the Week 5 task, to reach the approval boundary

Run 1 never proposed a visit (finding 5.3), so a second run used the Week 5 task wording.

`php artisan agrivisit:agent "Prepare and schedule a follow-up visit for farm UG-KYA-012"`

```
Goal: Prepare and schedule a follow-up visit for farm UG-KYA-012

I'll start by looking up the farm's profile to see its crops, outstanding issues and previous
advice, so the visit has a clear purpose.
+-----------+---------------------+---------+---------------+
| Iteration | Tool                | Outcome | Duration (ms) |
+-----------+---------------------+---------+---------------+
| 1         | farm_profile_lookup | OK      | 26.98         |
+-----------+---------------------+---------+---------------+
  Arguments: {"farm_id":"UG-KYA-012"}

I'll search the guidance corpus for causes of maize leaf damage, so the visit can focus on the
unresolved issue and any statements I make are backed by a source.
+-----------+-----------------+---------+---------------+
| Iteration | Tool            | Outcome | Duration (ms) |
+-----------+-----------------+---------+---------------+
| 2         | search_guidance | OK      | 1388.5        |
+-----------+-----------------+---------+---------------+
  Arguments: {"query":"maize leaf damage causes and what to check in the field"}

I'm proposing a visit to UG-KYA-012 on 2026-10-19 to confirm the cause of the maize leaf damage and
discuss bean yields — just needs your approval to book it.
+-----------+----------------+-------------------+---------------+
| Iteration | Tool           | Outcome           | Duration (ms) |
+-----------+----------------+-------------------+---------------+
| 3         | schedule_visit | APPROVAL_REQUIRED | 0.97          |
+-----------+----------------+-------------------+---------------+
  Arguments: {"farm_id":"UG-KYA-012","visit_date":"2026-10-19","purpose":"Follow up on unconfirmed maize leaf damage and discuss bean yield improvement."}
  Schedule a visit to UG-KYA-012 on 2026-10-19.

Waiting for officer approval. NOTHING HAS BEEN WRITTEN.
  Tool:      schedule_visit
  Arguments: {"farm_id":"UG-KYA-012","visit_date":"2026-10-19","purpose":"Follow up on unconfirmed maize leaf damage and discuss bean yield improvement."}
  Approval happens through the existing Week 4 approval flow. This command does not approve.

Summary:
Waiting for officer approval: Schedule a visit to UG-KYA-012 on 2026-10-19.

+-------------------+------------+-------------------+---------+--------------------------------------------------------------------------------------------------------------------------------------------------+
| Stop reason       | Iterations | Tokens (in / out) | Seconds | Trace                                                                                                                                            |
+-------------------+------------+-------------------+---------+--------------------------------------------------------------------------------------------------------------------------------------------------+
| AWAITING_APPROVAL | 3 / 6      | 8078 / 568        | 11.0    | C:\Users\user\OneDrive - African Palliative Care Association\Desktop\AgriVisit_Week2_Baseline\storage\app/agent-runs\20261011-192735-lcgrww.json |
+-------------------+------------+-------------------+---------+--------------------------------------------------------------------------------------------------------------------------------------------------+
```

Trace: `storage/app/agent-runs/20261011-192735-lcgrww.json`. **Visits table: 7 rows before, 7 after.**

### Cost (Claude Sonnet 5.5, $2 / $10 per million tokens)

| Run | Input tokens | Output tokens | Approx. cost |
|---|---|---|---|
| 1 | 17,173 | 1,406 | $0.05 |
| 2 | 8,078 | 568 | $0.02 |

Input grows each iteration because the whole conversation is resent (1,940 → 5,760 tokens per call in run 1). Prompt caching is not used yet.

## 5. Where the brief was wrong, ambiguous, or the run showed something new

1. **The guard misfired on an agent-composed query, and the agent reworded around it.** Run 1, iteration 2: `"maize leaf damage causes and how to diagnose"` was refused as **clinical**, because `diagnose` is in the clinical term list. Rule 2 worked exactly as written. But the term list was designed for officer requests about animals and people, and crop guidance uses the same words. The agent then called the refusal a "misfire" and reworded the query two iterations later. Here that was harmless, arguably even correct. But it is the behaviour the Week 1 guard was meant to rule out: "a refusal cannot be defeated by rephrasing." A model willing to reword a refused crop query would reword a refused dosing query too, and only the numeric dose pattern and the term list stand in the way. `Checklist/` is frozen, so the guard was not changed. Options for a later phase: narrow the clinical terms to animal and human context, and add to the prompt (v1.1) "If a search is refused by the guard, do not reword it to get round the refusal; report it." **This is the strongest finding of the phase for the report.**
2. **Output format not followed.** The prompt says "Plain sentences, no headings"; run 1's final answer used bold headings and bullets. Run 2's plans were plain. The prompt was not edited, because rule 5 makes any change a new version. Candidate for `v1.1`.
3. **"Prepare me for a visit" did not propose a visit.** The agent gathered and cited guidance, then asked the officer for a date instead of calling `schedule_visit`. That is defensible ("you propose; you never decide"), but it is not the "proposes a visit" path the brief expected. With "Prepare and schedule…" it chose 2026-10-19 itself and stopped at `AWAITING_APPROVAL`. If the deliverable needs the approval path from the shorter goal, v1.1 could say: when the goal is visit preparation, propose a date.
4. **No result code for a guard refusal.** `ToolResult` has no such code and was not changed, so a refused query returns `INVALID_ARGUMENTS` with the guard's category and approved refusal wording. It counts toward `REPEATED_FAILURE`, which is the right behaviour: an agent that keeps composing restricted queries is not recovering.
5. **The threshold is applied in the tool.** `Retriever::retrieve()` applies no threshold. Only `retrieveForCrops()` does, and it also reorders by crop. The tool calls `retrieve()`, the same embedding path, and filters at `agrivisit.retrieval.threshold` (0.65). This is also what makes the threshold testable with a fake retriever.
6. **"Record the version in every trace" without touching `app/Services/Agent/`.** Done with `VersionedAgentTraceWriter`, a subclass bound in place of the Phase 1 writer. It also records `model`, which Phase 1 traces lacked.
7. **Today's date.** The agent must propose a `visit_date` that is today or later, and the model has no clock. The prompt's Context ends with "Today's date is {{today}}.", filled by `PromptRepository` at load time. Run 2's proposed date (2026-10-19) shows it working.
8. **Week 4 side effect.** `tools.enabled` is global, so the Week 4 tool console (Gemini) is now also offered `search_guidance`. The Gemini translator drops `default` and `additionalProperties`, which it does not support, but the gate still enforces the closed schema. Six Week 4 tests asserted a three-tool catalogue and were updated to four.
9. **Fallbacks reversed.** Phase 1 turned the server-side refusal fallback on; this brief turns it off, for the trace-integrity reason in its comment. The config default was changed to empty as well, so a missing env line cannot turn it back on.
10. **Plans depend on `ANTHROPIC_THINKING_DISPLAY=updates`.** In run 1, iteration 3 had no text block; its plan came only from the model's progress notes. Without that setting the trace would show an empty plan for that step.

## 6. Agent Task Contract (generated)

`php artisan agrivisit:agent-contract`

````markdown
# AgriVisit Agent Task Contract

Generated by `php artisan agrivisit:agent-contract` from config, the tool registry and prompt `agent/v1.0`. Do not edit by hand. Model: `claude-sonnet-5-5`.

## Purpose

You are an assistant helping a Ugandan agricultural extension officer prepare for and carry out farm visits. The officer is the decision-maker. You propose; you never decide.

Given the officer's stated goal, use the available tools to gather what is needed and propose the actions that serve it. Take one action per turn. Before each action, state in one or two sentences why you are taking it.

## Inputs

- **Goal:** One free-text goal from the officer. Screened by the restricted-topic guard before the model is called; a refused goal ends the run as GUARD_REFUSED.
- **Officer:** `officer-001` (Extension Officer). Supplied by system configuration, not authenticated, and not sent to the model. Recorded on approval decisions.

## Tools available

| Tool | Access | Approval required | Description |
|---|---|---|---|
| `farm_profile_lookup` | read | no | Look up a farm by its ID to get its district, crops, last visit date, outstanding issues and previous advice. |
| `schedule_visit` | write | yes | Schedule a field visit to a farm on a given date; the officer must approve before it is booked. |
| `log_followup` | write | yes | Record a follow-up note and outcome against an existing visit; the officer must approve before it is saved. |
| `search_guidance` | read | no | Search the curated agronomy guidance corpus; returns passages above the relevance threshold, each with a chunk_ref to cite, or none. |

## Bounds

- Max iterations: **6**
- Max consecutive tool failures: **3** (a success resets the count)
- Runtime budget: **120 s**
- Actions per iteration: **1**. Parallel tool use is disabled in the request (disable_parallel_tool_use), so the iteration cap is a cap on actions taken. A repeated call (same tool, same arguments) is not invoked and ends the run.

## Stop reasons

| Stop reason | Produced when |
|---|---|
| `GOAL_REACHED` | The model answered without calling a tool. The task is done. |
| `ITERATION_CAP` | max_iterations reached. Partial results are handed to the officer. Limit: 6. |
| `NO_PROGRESS` | The same tool was called with the same arguments twice. |
| `REPEATED_FAILURE` | max_consecutive_failures tool failures in a row; the agent is not recovering. Limit: 3. |
| `AWAITING_APPROVAL` | A write tool needs the officer. The run suspends here; Phase 3 resumes it. |
| `RUNTIME_BUDGET` | Wall-clock budget exhausted. Limit: 120 s. |
| `GUARD_REFUSED` | The request was refused by the restricted-topic guard before anything ran. |
| `MODEL_ERROR` | The model could not be reached or returned an unusable reply. |

## Approval boundary

- Tools requiring approval: `schedule_visit`, `log_followup`
- The loop never grants approval: every tool is invoked without it. A write tool returns APPROVAL_REQUIRED, the run stops as AWAITING_APPROVAL, and nothing is written. Approval is a human act in the Week 4 approval flow.

## Prohibited

- Never state a pesticide, fungicide or herbicide dose, rate or concentration. Never give veterinary or clinical advice. If the goal requires it, say that it is outside what you can help with and stop.
- Every agronomic statement you make must cite the `chunk_ref` of a passage `search_guidance` returned to you. If you have no passage, say you did not find guidance. Do not supply it from your own knowledge.
- Never say an action has been taken. Write actions require the officer's approval, which happens outside this conversation.
- One tool call per turn.
- Restricted-topic guard categories: dosing, clinical. Applied to the officer's goal (before the model is called) and every search_guidance query the agent composes (inside the tool).

## Evidence

- Location: `storage/app/agent-runs`
- One JSON file per run, named {run_id}.json, written on every stop.
- Run fields: `prompt_version`, `model`, `run_id`, `goal`, `limits`, `started_at`, `ended_at`, `duration_seconds`, `iterations`, `consecutive_failures`, `status`, `stop_reason`, `summary`, `total_tokens`, `steps`, `pending`, `signatures`, `messages`
- Step fields: `iteration`, `plan`, `tool`, `arguments`, `outcome`, `message`, `duration_ms`, `input_tokens`, `output_tokens`, `tool_calls_proposed`
````

## 7. Next

- **Phase 3:** browser workflow and resuming a suspended run after approval. `AgentRun::$pending` carries `tool_use_id`; the run must be persisted between requests.
- **Prompt v1.1 candidates:** findings 5.1, 5.2 and 5.3.
- **Phase 4 traces:** run 1 (recovery after a failure) and run 2 (approval boundary) are already usable. A limit stop can be produced with `--max-iterations=2`.
