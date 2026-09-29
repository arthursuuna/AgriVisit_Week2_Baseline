# Implement Week 3 Phase 3 — Grounded Drafting and RAG Evaluation

Phases 1 and 2 are closed: 301 chunks, all embedded, retrieval verified. This
phase connects retrieval to the checklist drafter, inverts the grounding rule,
and builds the 15-case RAG evaluation.

This is the phase that changes `ChecklistDrafter`, the prompt and the UI. Those
were out of scope before; they are the work now.

## 1. Decisions already made

| Decision | Value | Why |
|---|---|---|
| Relevance threshold | 0.65 | Phase 2 measured 0.58 for an unanswerable query and 0.70–0.87 for real ones. 0.65 sits in the gap |
| Chunks supplied per draft | up to 8 | Enough evidence for 5–10 items without flooding the prompt |
| Crop handling | filter with fallback | R-01 showed crop identity carries little weight in the embedding. Restrict to the farm's crops first, then top up |
| Minimum items | 3, not 5 | Under v2.0 an item needs supporting evidence. Forcing 5 would push the model to invent |
| No evidence above threshold | a new refusal-like outcome | Drafting with no evidence is exactly what v2.0 forbids |

## 2. What to build

### A. Query construction — `app/Services/Retrieval/FarmQueryBuilder.php`

The farm profile cannot be the query. Most of it is irrelevant to retrieval
(farm ID, area, water source) and dilutes the signal.

Build one query string from, in order:

1. each crop name,
2. each outstanding issue verbatim,
3. the officer notes, if any.

Return a list of queries, not one concatenated string: one per outstanding issue
(with the crop names appended), plus one covering the crops alone. Retrieve for
each and merge the results, keeping the highest score per chunk. A single blended
query returns passages that are vaguely about everything.

### B. Crop filtering — extend `CosineRetriever`

Add an optional crop constraint to retrieval:

```php
public function retrieveForCrops(string $query, array $crops, ?int $k = null): array;
```

1. Score only chunks whose document's `crops` overlaps `$crops`.
2. If fewer than `$k` results score at or above the threshold, top up from the
   whole corpus, still threshold-filtered.

The fallback exists because genuinely crop-agnostic material — record keeping,
soil and water, marketing — lives in whichever manual happened to cover it.

Do **not** boost by score adjustment. A hard filter with a fallback is
explainable in one sentence; a tuned boost is not.

### C. Context assembly — `app/Services/Retrieval/EvidenceSet.php`

A small value object holding the retrieved chunks and rendering them for the
prompt:

```
[C-1] Beans Training Manual — 2.6: Planting
Planting should be done on the onset of rains...

[C-2] Maize Training Manual — 3.2: Land preparation
...
```

Number the evidence **per request** (`C-1`, `C-2`, …), not by database chunk ref.
The model cites the local label; the application maps it back. This keeps the
prompt short and makes a fabricated label obvious — anything outside `C-1` to
`C-8` was invented.

The object must expose: the label-to-chunk map, the chunk for a given label, and
the count.

### D. Prompt v2.0 — `resources/prompts/checklist/v2.0/`

Copy v1.1 and change these sections. Keep the other three as they are.

**CONTEXT** — replace the last line ("No agronomy document collection is
connected to you yet") with:

```
- You are given numbered evidence passages retrieved from a curated collection of
  Ugandan agricultural extension manuals. They are the only source of agronomic
  guidance available to you.
- Evidence is retrieved automatically and may be incomplete, partly relevant, or
  occasionally irrelevant. Judge each passage on whether it actually supports the
  item you are drafting.
```

**CONSTRAINTS** — replace constraint 5 with:

```
5. Every item must be supported by one of the numbered evidence passages, and
   must carry that passage's label in its "grounding" field, for example "C-3".
   - Cite only labels that appear in the evidence supplied to you. Never invent a
     label.
   - If a passage does not actually support the item, do not cite it. Drop the
     item instead.
   - If you know something from your own training that the evidence does not
     support, do not include it. Omission is correct; an uncited claim is not.
```

**OUTPUT FORMAT** — change the `grounding` field description from the fixed
string `"ungrounded"` to `"The label of the supporting evidence passage, e.g.
C-3"`.

**FAILURE BEHAVIOUR** — replace the first row with:

```
- If the evidence supports fewer than 5 items, produce only the items it
  supports. Between 3 and 10 items is acceptable. Do not pad with general
  knowledge.
- If the evidence supports no items at all, return {"summary": "", "items": []}.
```

The user template gains an evidence block **above** the farm profile:

```
--- BEGIN EVIDENCE (numbered passages; cite these labels) ---
{{EVIDENCE}}
--- END EVIDENCE ---
```

### E. Parser — `ChecklistParser`

The grounding check inverts. Add a constructor parameter or method accepting the
set of valid labels for this request.

- Reject an item whose `grounding` is not in the supplied label set. This catches
  a fabricated citation deterministically, the same mechanism that previously
  enforced `"ungrounded"`.
- Accept 3 to 10 items for v2.0. Keep 5 to 10 for v1.1 — version the range in
  config rather than hard-coding one.
- Keep every other check unchanged.

### F. Wire into `ChecklistDrafter`

New order of operations:

1. Restricted-topic screen on the notes (unchanged).
2. Build queries from the farm profile (A).
3. Retrieve with crop filtering and the 0.65 threshold (B).
4. **If no chunks clear the threshold**, return a new outcome
   `DraftResult::STATUS_NO_EVIDENCE` with a message stating that no supporting
   guidance was found for this farm, and write a trace. Do not call the model.
5. Assemble the evidence set (C) and render the prompt.
6. Model call (unchanged).
7. Restricted-topic screen on the output (unchanged).
8. Parse with the valid label set (E).
9. Map each item's label back to its chunk, so the result carries the real
   citation.
10. Write the trace (H).

### G. UI — `resources/views/checklist/index.blade.php`

- Replace the "Items are ungrounded" banner. When items are drafted, state that
  each item cites a source the officer can verify, and that approval is still
  required.
- Under each item's rationale, show the citation: document title, section, and
  the chunk ref.
- Make the citation expandable to reveal the quoted passage text. An officer who
  cannot see the source cannot verify the claim, which is the whole point of
  grounding.
- Add a banner for the `no_evidence` outcome.
- Keep the existing metadata footer; add the number of evidence passages supplied.

### H. Traces — extend `TraceWriter` payload

Record, for every drafting attempt:

- the queries used for retrieval,
- every chunk supplied: label, chunk ref, document, section, score,
- every label actually cited in the output,
- which supplied chunks went uncited.

This is what makes the difference between a retrieval failure and a grounding
failure visible after the fact. Without it, section 4 cannot be completed
honestly.

### I. RAG evaluation — 15 questions

`database/evaluation/rag_questions.json`, with fields: `id`, `question`,
`category` (`answerable` | `partial` | `unanswerable`), `crops`,
`expected_source` (document ref, or null), `notes`.

Write **15 questions: 6 answerable, 5 partial, 4 unanswerable.** Draw them from
the corpus you actually have, not from what an agronomy corpus might contain.

- **Answerable** — the corpus clearly covers it. Example: "How should rooting
  media be prepared for coffee cuttings?" (DOC-003).
- **Partial** — the corpus covers part of it. Example: "What should I check on a
  coffee nursery each week, and when should plantlets be hardened off?" — the
  weekly checks are present; the hardening-off section was removed in Phase 1.
- **Unanswerable** — nothing in the corpus addresses it, and the correct
  behaviour is to say so. Use genuinely out-of-scope subjects: livestock health,
  irrigation equipment specifications, market prices, a crop not in the corpus.
  Do **not** use restricted topics; those are already covered by the guard and
  would test the wrong thing.

`app/Console/Commands/RunRagEvaluation.php` → `php artisan agrivisit:rag-eval`

For each question: retrieve with crop filtering and threshold; if nothing clears
the threshold, record `declined`; otherwise send the question and the evidence to
the model using a small grounded-answer prompt at
`resources/prompts/answer/v1.0/`, which answers **only** from the supplied
passages, cites labels, and says plainly when the evidence is insufficient.

Write `evaluation/rag-eval-v2.0.md` with a row per question: id, category,
top score, whether the expected source was supplied, whether the answer cited it,
and the verdict.

Expected outcomes:

| Category | Correct behaviour |
|---|---|
| Answerable | Expected source supplied and cited; answer grounded |
| Partial | Answers the covered part, states plainly what the evidence does not cover |
| Unanswerable | Declines — either nothing clears the threshold, or the model says the evidence does not address it. Never fabricates |

## 3. Verification

```bash
php artisan test
php artisan agrivisit:rag-eval
php artisan serve
```

Manual checks in the console:

1. Draft for **UG-KYA-012** (beans and maize, leaf damage reported). Confirm every
   item carries a citation naming a real document and section, and that the
   passage can be expanded and does support the item.
2. Draft for **UG-MTY-045** (coffee and banana). Note that the corpus has no
   banana document — check whether items are produced for banana and, if so,
   what they cite. This is a deliberate gap and its handling is a finding.
3. Draft for **UG-KYA-077** (beans only, no history).
4. Enter a note asking for a pesticide dose. The guard must still refuse before
   any retrieval or model call.

Tinker checks:

```php
// no item may cite a label outside its supplied evidence — check a trace
// every drafted item must map to a real chunk
App\Models\Chunk::whereIn('chunk_ref', [/* refs cited in a recent trace */])->count()
```

## 4. Required deliverable — three documented failures

The brief requires **at least three retrieval or grounding failures, with causes**.
One already exists (R-01, crop confusion). Find at least two more from the 15-case
run and the manual drafts, and for each record:

- the query or farm,
- what was retrieved and what was cited,
- whether it is a **retrieval** failure (the supporting passage was never
  supplied) or a **grounding** failure (the passage was supplied and then
  ignored, contradicted, or cited for a claim it does not support),
- the cause,
- whether it was fixed or accepted, and why.

The distinction matters and the traces from (H) are what make it provable. Do not
label a failure without checking the trace.

## 5. Out of scope

Tools and function calling, the agent loop, persistent memory, and any change to
the ingestion pipeline or the dosing filters. Do not re-ingest or re-embed.

## 6. Report back

State: every file changed, the rag-eval results table, the four manual checks with
what each produced, the three or more documented failures with their
classification and cause, and anything in this brief that proved wrong or
ambiguous.
