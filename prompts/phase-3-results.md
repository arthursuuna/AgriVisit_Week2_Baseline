# Week 3 Phase 3 — Grounded Drafting and RAG Evaluation: Results

**Implemented from:** `prompts/IMPLEMENT-PHASE-3.md`
**Date:** 2026-09-29
**Status:** **Implementation complete; verification partly blocked.**

| Part | State |
|---|---|
| Code (sections A–I) | Complete |
| Automated tests | **85 pass** (26 new) |
| RAG evaluation (15 questions) | **8 PASS**, 0 FAIL, **7 ERROR** (2 timeouts, 5 daily quota) |
| Manual checks (4) | Check 4 passes; checks 1–3 **not yet run** |
| Documented failures (3 required) | **2 documented**; the third is pending the remaining runs |

**The blocker:** the Google API key is on the **free tier**, which allows **5 requests per minute and 20 requests per day** for `gemini-3.6-flash`. The daily limit was reached during the evaluation run (`GenerateRequestsPerDayPerProjectPerModel-FreeTier`, value 20). No further model calls succeed with this model until the quota resets. Embedding calls are on a separate quota and still work.

Nothing has been committed.

---

## What was built

### New files

| File | Section | Purpose |
|---|---|---|
| `app/Services/Retrieval/FarmQueryBuilder.php` | A | One query per outstanding issue (crop names appended), one for officer notes, one for the crops alone |
| `app/Services/Retrieval/EvidenceSet.php` | C | Numbers passages `C-1`…`C-n` per request; label-to-chunk map, lookup, count, prompt rendering, citations, trace rows |
| `resources/prompts/checklist/v2.0/system.md` | D | v1.1 with CONTEXT, CONSTRAINT 5, OUTPUT FORMAT and FAILURE BEHAVIOUR changed as specified |
| `resources/prompts/checklist/v2.0/user.md` | D | Evidence block above the farm profile |
| `resources/prompts/answer/v1.0/system.md`, `user.md` | I | Grounded-answer prompt: answer only from passages, cite labels, state what is not covered |
| `app/Console/Commands/RunRagEvaluation.php` | I | `php artisan agrivisit:rag-eval` |
| `database/evaluation/rag_questions.json` | I | 15 questions: 6 answerable, 5 partial, 4 unanswerable |
| `tests/Feature/FarmQueryBuilderTest.php` | — | 5 tests |
| `tests/Feature/EvidenceSetTest.php` | — | 5 tests |
| `tests/Feature/ChecklistDrafterTest.php` | — | 6 tests, with stub model and retriever |

### Changed files

| File | Section | Change |
|---|---|---|
| `config/agrivisit.php` | E | Per-version settings (`v1.0`/`v1.1`: 5–10 items, ungrounded; `v2.0`: 3–10 items, grounded); new `retrieval` block (threshold 0.65, up to 8 passages) |
| `app/Services/Retrieval/Retriever.php` | B | Adds `retrieveForCrops()` |
| `app/Services/Retrieval/CosineRetriever.php` | B | Threshold, `retrieveForCrops()` / `rankForCrops()` (crop filter, then top-up), decoded vectors cached per instance |
| `app/Services/Checklist/ChecklistParser.php` | E | `withRange()`; `parse()` takes the valid labels. With labels, every item must cite one; without, `"ungrounded"` as before |
| `app/Services/Checklist/DraftResult.php` | F | `STATUS_NO_EVIDENCE`, `noEvidence()`, `evidenceCount` |
| `app/Services/Checklist/ChecklistDrafter.php` | F, H | New order of operations; citations mapped to real chunks; retrieval and citation fields in every trace |
| `app/Providers/AgriVisitServiceProvider.php` | — | Drafter gets the query builder and retriever; retriever gets the threshold |
| `resources/views/checklist/index.blade.php` | G | Grounded-draft banner, expandable citation with the quoted passage, `no_evidence` banner, evidence count in the footer |
| `tests/Feature/ChecklistParserTest.php` | — | 5 tests added |
| `tests/Feature/CosineRetrieverTest.php` | — | 5 tests added (crop filter, fallback, threshold) |
| `.env` | — | `PROMPT_VERSION` changed from `v1.1` to `v2.0` (backup in the scratch folder) |

### Order of operations in `ChecklistDrafter` (v2.0)

1. Restricted-topic screen on the notes
2. Build queries from the farm profile
3. Retrieve per query with the crop filter and 0.65 threshold; merge, keeping each chunk's best score; farm's crops first, then the top-up; up to 8
4. No passage cleared the threshold → `no_evidence`, trace written, **no model call**
5. Render the evidence and the prompt
6. Model call
7. Restricted-topic screen on the output
8. Parse with the valid label set; a label outside it discards the draft
9. The model returns no items → `no_evidence` (stage `model`)
10. Map each label to its chunk; write the trace (queries, evidence, cited and uncited labels)

v1.x prompts skip retrieval and behave exactly as in Week 2, so `agrivisit:evaluate --prompt=v1.0` still works.

---

## Tests

| Test class | Tests | New this phase |
|---|---|---|
| `ChecklistParserTest` | 10 | 5 — labels accepted, outside label rejected, `ungrounded` rejected in grounded mode, 3–10 range, honest empty list |
| `CosineRetrieverTest` | 12 | 5 — crop matches first, crop filter fills k, unknown crop falls back, threshold applies to the top-up, unanswerable returns nothing |
| `FarmQueryBuilderTest` | 5 | 5 — one query per issue, notes query, crops-only farm, irrelevant fields excluded, crop names normalised |
| `EvidenceSetTest` | 5 | 5 — per-request numbering, unknown label, rendering, citation, empty set |
| `ChecklistDrafterTest` | 6 | 6 — no evidence skips the model, guard refuses before retrieval, citations and trace, fabricated label discards the draft, model finding no support, v1.1 unchanged |
| **Full suite** | **85 pass** | |

The drafter tests caught a real bug: `config("agrivisit.checklist.versions.v2.0")` reads the key `v2.0` as `v2` → `0` and returns nothing, so v2.0 silently ran in **ungrounded** mode. The version is now looked up in the array directly.

---

## RAG evaluation

`php artisan agrivisit:rag-eval` · model `gemini-3.6-flash` · temperature 0.2 · threshold 0.65 · up to 8 passages · answer prompt v1.0

Verdicts: PASS, FAIL, REVIEW (needs a human verdict) and ERROR (infrastructure failure, never counted as behaviour).

| ID | Category | Crops | Top score | Passages supplied | Expected source | Supplied | Cited | Coverage | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| R01 | answerable | coffee | 0.805 | 8 × DOC-003 | DOC-003 | yes | yes | full | **PASS** |
| R02 | answerable | beans | 0.760 | 8 × DOC-001 | DOC-001 | yes | — | — | ERROR (timeout) |
| R03 | answerable | maize | 0.756 | 8 × DOC-002 | DOC-002 | yes | yes | full | **PASS** |
| R04 | answerable | beans | 0.765 | 8 × DOC-001 | DOC-001 | yes | — | — | ERROR (timeout) |
| R05 | answerable | coffee | 0.851 | 8 × DOC-003 | DOC-003 | yes | yes | full | **PASS** |
| R06 | answerable | maize | 0.762 | 8 × DOC-002 | DOC-002 | yes | yes | full | **PASS** |
| R07 | partial | coffee | 0.815 | 8 × DOC-003 | DOC-003 | yes | yes | partial | **PASS** (see F-02) |
| R08 | partial | maize | 0.739 | 8 × DOC-002 | DOC-002 | yes | yes | partial | **PASS** |
| R09 | partial | beans | 0.722 | 8 × DOC-001 | DOC-001 | yes | — | — | ERROR (quota) |
| R10 | partial | coffee, banana | 0.732 | 8 × DOC-003 | DOC-003 | yes | — | — | ERROR (quota) |
| R11 | partial | maize, beans, tomato | 0.788 | 6 × DOC-001, 2 × DOC-002 | DOC-002 | yes | — | — | ERROR (quota) |
| R12 | unanswerable | — | — | none | — | — | — | declined | **PASS** |
| R13 | unanswerable | — | — | none | — | — | — | declined | **PASS** |
| R14 | unanswerable | coffee | 0.696 | 5 × DOC-003 | — | — | — | — | ERROR (quota) |
| R15 | unanswerable | cassava | 0.714 | 8 × DOC-003 | — | — | — | — | ERROR (quota) |

**Final run: 8 PASS, 0 FAIL, 0 REVIEW, 7 ERROR.** No behavioural failures; every ERROR is a timeout or the daily quota. `evaluation/rag-eval-v2.0.md` holds this run.

Retrieval ran for all 15 (embeddings are not affected by the quota), so the "Top score", "Passages supplied" and "Supplied" columns are complete. Only the model's answers are missing for the ERROR and pending rows.

### Answers so far

| ID | Answer (shortened) |
|---|---|
| R01 | Rooting media is black forest soil, white lake sand or sawdust [C-1]; soil and sand are sieved and solarised under black polythene for 6–12 weeks [C-…] |
| R03 | Test moisture by traditional biting, the salt bottle method, or a moisture meter [C-1, C-4] |
| R05 | Pick aphids off infested shoots by hand, crush them and compost them; eliminate the ants that protect them [C-1] |
| R06 | 500–600 mm rainfall, well distributed; optimum temperature by maize type, e.g. 20–30 °C temperate, 17–20 °C highland tropical [C-1] |
| R07 | Plantlets are hardened off in a hardening shade until 7–8 months old [C-1, C-4]. **Not covered:** "what should be checked … on a weekly basis" |
| R08 | Dry grain to 13% moisture or below before bagging [C-2, C-3]; store in hermetic/PICS bags or silos… **Not covered:** "the market price maize will fetch this season" |
| R12, R13 | Declined without a model call: nothing cleared 0.65 |

### Early observations from retrieval (model answers pending)

- **R14 and R15 were not declined by the threshold.** A market-price question (0.696) and a cassava question (0.714) both cleared 0.65, so 5 and 8 coffee-nursery passages were supplied. The 0.65 threshold, set from Phase 2's single negative control (0.58), does not catch every out-of-scope question. Whether the model then correctly declines is what the pending runs will show.
- **R11** supplied 6 beans passages and 2 maize passages for an intercropping question. Tomato has no document, so the fallback filled from other crops, as designed.

---

## Manual checks

| # | Check | State | Result |
|---|---|---|---|
| 1 | Draft for **UG-KYA-012** (beans and maize, leaf damage) | **Not run** (quota) | — |
| 2 | Draft for **UG-MTY-045** (coffee and banana; no banana document) | **Not run** (quota) | — |
| 3 | Draft for **UG-KYA-077** (beans only, no history) | **Not run** (quota) | — |
| 4 | Note asking for a pesticide dose | **Pass** | Refused at `pre-generation`, category `dosing`. The trace has no retrieval and no model entry, so the guard ran before both. Trace `bb9b0d43-3d53-444c-8823-8ee5ae9fcf42` |

A script for checks 1–3 is ready. It drafts through the same `ChecklistDrafter` the console uses, prints each item with its citation and the trace summary, and renders the page to confirm the expandable citations.

---

## Documented failures

Two of the required three are documented. Each was classified from its trace.

### R-01 — Crop confusion (from Phase 2)

| | |
|---|---|
| **Query** | "when to plant maize for the first rains" |
| **Retrieved** | DOC-001-C021 (beans, 2.6 Planting) at 0.7032; best maize chunk DOC-002-C019 at 0.7031 |
| **Type** | Retrieval |
| **Cause** | The embeddings matched the topic (planting at the onset of rains) but gave crop identity almost no weight |
| **Fixed or accepted** | **Fixed** by the crop filter with fallback (section B): a maize farm's queries now score maize documents first. Covered by `CosineRetrieverTest::test_crop_matches_come_before_higher_scoring_chunks_from_other_crops`. |

### F-02 — Compound question loses its second half (R07)

| | |
|---|---|
| **Question** | "What should I check on a coffee nursery each week, and when should plantlets be hardened off?" |
| **Retrieved** | 8 passages, all DOC-003: C063 (0.815, 5.0 Introduction), C066 (0.812), C048 (0.807), C019 (0.776, 2.7 Hardening shed), C029, C062, C050, C030 |
| **Cited** | C063, C066, C019: all about hardening off |
| **The passage that answers the first half** | DOC-003-C110, section "11. Management of…": "Check for disease/pest infestation (once every week)", "Check the moisture/water in the system (once every week)" |
| **Was it supplied?** | **No.** It scored 0.692 (above the threshold) but ranked **38th of 301**, outside the top 8 |
| **Type** | **Retrieval failure.** The model did not ignore evidence: it reported the gap ("does not specify what should be checked … on a weekly basis") instead of inventing an answer, which is correct grounding behaviour |
| **Cause** | The whole compound question was embedded as one query. The hardening-off half matched many strongly-worded passages and filled every slot; the weekly-checks half never reached the top 8. The harness scored it PASS, because the model answered one part and named the gap, but only the trace shows the gap was a retrieval failure. |
| **Fixed or accepted** | **Accepted for now.** In the drafter this is already mitigated, because each outstanding issue becomes its own query (section A). The evaluation command sends each question as a single query, as the brief specifies. Splitting compound questions would be the fix. |

### Third failure — pending

Candidates, to be confirmed or ruled out from the pending runs and manual checks:

- **R14 / R15:** out-of-scope questions cleared the threshold. If the model answers from coffee-nursery passages (for example, transferring coffee cutting selection to cassava), that is a grounding failure.
- **Check 2 (UG-MTY-045):** how banana items are handled with no banana document.
- **Guard vs seed rates:** `RestrictedTopicGuard::screenOutput()` treats "30 kg per acre" as a dose. Seed rates are kept in the corpus, so a draft quoting one would be refused after generation.

---

## Where the brief proved wrong or ambiguous

1. **"Keep the other three as they are."** The prompt has six sections; the brief changes four (CONTEXT, CONSTRAINTS, OUTPUT FORMAT, FAILURE BEHAVIOUR), which leaves two (ROLE, TASK). Both were kept unchanged.
2. **Officer notes in queries.** Section A lists the notes in the query order but not in the list of queries. They were added as their own query, with crop names appended.
3. **An empty item list under v2.0.** The prompt tells the model to return `{"items": []}` when the evidence supports nothing, but the 3–10 range would reject that as a format error. It is treated as `no_evidence` (stage `model`) instead.
4. **`retrieveForCrops()` on the interface.** Added to `Retriever` as well as `CosineRetriever`, so the drafter depends on the interface and can be tested with a stub.
5. **Config keys with dots.** Version keys such as `v2.0` cannot be read with Laravel's dot notation; see the bug under Tests.
6. **Free-tier quota.** The brief assumes enough model calls for 15 questions plus manual drafts and re-runs. The free tier's 20 requests per day is not enough for one full evaluation run with retries.

### Infrastructure changes made to the evaluation command

- Model calls are paced (default 13 s apart) to stay under 5 per minute.
- A 429 or 503 is retried up to 3 times, waiting as long as the API asks.
- Infrastructure failures are recorded as **ERROR**, separate from behavioural FAIL.

---

## To finish Phase 3

1. **Get model capacity**, either:
   - wait for the daily quota to reset, then rerun `php artisan agrivisit:rag-eval` and the manual checks, or
   - enable billing on the Google project (removes the 20-per-day limit), or
   - switch `LLM_MODEL` to another Gemini model with its own quota. This changes the model the evaluation is measured on, so it should be recorded.
2. Run manual checks 1–3.
3. Complete the RAG table (R02, R04, R09, R10, R11, R14, R15).
4. Document the third failure from those results.

---

## Related files

- `prompts/IMPLEMENT-PHASE-3.md` — the brief
- `prompts/phase-2-results.md` — retrieval results, R-01 and the 0.58 negative control
- `evaluation/rag-eval-v2.0.md` — generated evaluation report (overwritten by each run)
- `storage/app/traces/` — one trace per draft and per evaluation question
