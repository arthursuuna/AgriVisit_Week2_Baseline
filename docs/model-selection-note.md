# Model Selection Note

**AgriVisit — BSE4104 Week 2** · AgriVisit_Capstone · Owner: Jovan Bwire (AI Engineering Lead)

## Task the model must perform

Given a synthetic farm profile and optional officer notes, draft 5–10 field-visit
checklist items as strict JSON, obeying hard constraints (no doses, no clinical
advice, no fabricated facts, no citations). This is a constrained structured-generation
task over short inputs — roughly 400–900 input tokens, 400–800 output tokens.

## Criteria and candidate comparison

Assessed against the eight selection criteria from Lecture 3.

| Criterion | Requirement for this task | Claude Sonnet 4.6 | GPT-4o mini | Llama 3.1 8B (local) |
|---|---|---|---|---|
| Capability | Draft plausible agronomic inspection items from a profile | Strong | Adequate | Weak on domain nuance |
| Instruction following | Must hold 6 hard constraints simultaneously | Strong | Moderate | Unreliable under multiple constraints |
| Context length | ~2k tokens; ample headroom needed for Week 3 RAG | 200k | 128k | 128k |
| Structured output | Must return parseable JSON every time | Reliable | Reliable | Frequent format drift |
| Latency | Under ~30 s (success measure S7) | 3–8 s typical | 2–5 s typical | Depends on local hardware |
| Cost | Student budget; ~300 dev calls/week | Moderate | Lowest | No API cost, hardware cost instead |
| Privacy | Synthetic data only; no PII leaves the country | Acceptable | Acceptable | Strongest |
| Deployment | Must work from Laravel over HTTP | API | API | Requires GPU serving |

## Decision

**Claude Sonnet 4.6, temperature 0.2, max_tokens 1500.**

Instruction following is the binding criterion, not raw capability. The prompt
carries six hard constraints and the output is rejected outright if any is broken,
so a model that obeys constraints reliably is worth more here than one that writes
more fluently. Temperature is low because this is a constrained generation task where
variability is a defect, not a feature.

Privacy is not a differentiator in this project: all farm data is synthetic and
team-created, so no real farmer information reaches any provider. Had the project used
real records, the local option would have moved up sharply.

The local option was rejected on format drift. When output validation is strict — and
ours rejects any response that is not schema-conformant JSON — a model that drifts out
of format converts directly into failed runs against success measure S1.

## Cost estimate

At roughly 700 input and 600 output tokens per call, and around 300 development calls
per week across the team, weekly spend stays in low single-digit dollars. The evaluation
set is 10 calls per run, so version comparisons are inexpensive enough to run often.

## Reversibility

The provider sits behind an `LlmClient` interface with two implementations
(`AnthropicClient`, `OpenAiClient`), selected by the `LLM_PROVIDER` environment
variable. Re-running `php artisan agrivisit:evaluate` against a different provider
requires no code change, which is what makes this decision revisable on evidence
rather than on preference.

## Review trigger

Revisit this choice if: the Week 3 retrieval context pushes typical input beyond
~20k tokens; measured p50 latency exceeds 30 s; or the evaluation pass rate on the
10-case set falls below 8/10 after two prompt iterations.
