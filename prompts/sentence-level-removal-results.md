# Sentence-Level Dose Removal — Results

**Implemented from:** `prompts/phase-1-sentence-level-removal.md`
**Date:** 2026-09-29
**Follows:** `prompts/final-dosing-fix-results.md` (the "suggested follow-up" this implements)
**Status:** Complete. All safety checks return 0 and the recovery check passes. Two implementation changes beyond the brief were needed to get there (see [Departures from the brief](#departures-from-the-brief)). Both open items were declined and are recorded as accepted costs (see [Decisions on the open items](#decisions-on-the-open-items)).

---

## Summary

| Check | Result |
|---|---|
| Safety 1: `Roundup`, `150mls` | **0** |
| Safety 2: `Cypermethrin`, `Mancozeb`, `Carbend` | **0** |
| Safety 3: `per litre of water`, `in 20 litres` | **0** |
| Recovery: `disease/pest infestation` | **1** (DOC-003-C110) |
| Total chunks | **301** (was 290) |
| `RestrictedSectionStripperTest` | 25 tests pass (5 new) |
| Full test suite | 52 tests pass |

### Per document

| Document | Words | Chunks | Sections cut | Sentences removed | Chunks dropped |
|---|---|---|---|---|---|
| DOC-001 Beans Training Manual | 20,767 | 138 | 2 | 0 | 0 |
| DOC-002 Maize Training Manual | 12,700 | 52 | 0 | 11 | 2 |
| DOC-003 Clonal Robusta Coffee Nursery Manual | 17,195 | 111 (was 100) | 0 | 21 | 3 (was 13) |
| **Total** | **50,662** | **301** | **2** | **32** | **5** (was 15) |

---

## What changed

1. **`RestrictedSectionStripper::stripMixRatioSentences(string $text): array`** (new). Returns `['text' => string, 'sentences_removed' => int]`.
   - Heading lines (those `looksLikeHeading()` accepts) pass through untouched, unless the heading line itself holds a mix ratio.
   - Body text between headings is split after `.`, `!` or `?`, and before each run of bullet markers `➢ • ▪ ●`.
   - Any piece matching `MIX_RATIO_PATTERN` is dropped and counted.
   - The whitespace between pieces is kept, so line breaks survive and `Chunker` still sees the same lines and section boundaries.
2. **`IngestCorpus`:** calls `stripMixRatioSentences()` before `strip()` (see departure 1), stores `sentences_removed`, and adds a "Sentences cut" column to the summary table.
3. **Migration `2026_01_01_000004_add_sentences_removed_to_documents_table`:** adds `documents.sentences_removed` (unsigned integer, default 0).
4. **`BuildCorpusRegister`:** adds "Sentences removed" to the totals row and the Documents table, plus a "Sentences removed at ingestion" section.
5. **`chunkIsRestricted()`:** unchanged, and kept as the backstop.

### Order of defence

| Order | Layer | Scope |
|---|---|---|
| 1 | Sentence-level mix-ratio removal (new) | One sentence or bullet item |
| 2 | Section exclusion by heading, and the line-level rate check (`strip()`) | A section, or one line |
| 3 | Chunk-level two-signal filter (backstop) | A whole chunk |

The brief ordered section exclusion first; see departure 1 for why sentence removal now runs first.

---

## Departures from the brief

### 1. Sentence removal runs before `strip()`, not after

On the first run, safety checks 2 and 3 failed. `strip()`'s line-level rate check was cutting single PDF lines out of the middle of dose sentences, leaving broken halves that no longer looked like a ratio:

> a chemical spray of Carbenduzium (formulation - 500 gms water.
> …using chemicals such as Chloropyrifos ethyl (Dursban), cypermethrin ●●Soil steam sterilization…

Removing whole sentences first avoids this. Section exclusion still works afterwards, because heading lines are never altered.

### 2. `MIX_RATIO_PATTERN` allows a short product name before the volume

A real dose was getting through:

> Just before placement of cuttings in the rooting media dip the cuttings in a solution made from 50gms of copper oxychloride in 20 litres of water.

The pattern expected the unit to be followed directly by `in`/`per`. In the previous phase this chunk was caught only because a *different* sentence in it said "fungicides". That sentence is now removed, so the chunk-level backstop no longer fires.

The pattern now allows an optional `of <up to 40 characters, no digits>` before `in N litres`:

```php
'/\b\d+(?:[.,]\d+)?\s*(?:ml|mls|g|gm|gms|gram|grams|kg|l|lt|lts|litre|litres|liter|liters)\s*'
. '(?:\/\s*|per\s+|(?:of\s+[^\d.;:]{1,40}?\s+)?in\s+(?:\d+(?:[.,]\d+)?\s*)?)'
. '(?:litre|litres|liter|liters|l|lt|lts)\b/iu'
```

Checked against control strings:

| String | Match |
|---|---|
| `50gms of copper oxychloride in 20 litres of water` | Yes |
| `Mancozeb at 50gms in 20 litre of water` | Yes |
| `Water the seedlings with 15 litres of water each morning` | No |
| `Recommended seed rate is 30 kg per acre` | No |
| `Use 20 kg of seed in the field, then 5 litres of water` | No |

### 3. `●` added to the bullet markers

The brief listed `➢ • ▪`. DOC-003, the manual this fix is meant to recover, uses only `●` (250 times). Without it, a dose sentence and the unrelated bullet items after it would be removed together.

| Marker | Where it is used |
|---|---|
| `➢` | DOC-002 (426) |
| `•` | DOC-001 (330), DOC-002 (34) |
| `●` | DOC-003 (250) |

---

## Tests

5 tests added to `RestrictedSectionStripperTest`:

| Test | Source |
|---|---|
| `Check for pests weekly. Apply Mancozeb at 50gms in 20 litres of water. Keep the cover airtight.` keeps both outer sentences, removes only the middle one, count 1 | Brief, test 1 |
| `➢ Water twice daily ➢ Apply Cypermethrin 70ml in 20 litres ➢ Open cages for two hours` removes only the middle item | Brief, test 2 |
| A passage with no ratio is returned unchanged, count 0 | Brief, test 3 |
| The DOC-002 Roundup passage survives sentence removal (count 0) but is still caught by `chunkIsRestricted()` | Brief, test 4 |
| `…50gms of copper oxychloride in 20 litres of water…` is removed | Added for departure 2 |

---

## Recovered guidance

Checked by searching the re-ingested corpus for a phrase from each item.

| Guidance (DOC-003) | Status |
|---|---|
| Check for disease/pest infestation once every week | Present (C110) |
| Check the moisture/water in the system once every week | Present (C110) |
| Open the cages early morning; keep 50% shade cover | Present (C095) |
| Avoid overwatering | Present (C095) |
| Hand-pick and crush aphids | Present (C089) |
| Get rid of the ants that protect aphids | Present (C089) |
| Keep the polythene cover airtight | Present (C062) |
| Watering schedule by polypot size | Present (C062) |
| Rooting-hormone steps | Present (C057) |
| Pre-harvest care of suckers | Present (C044) |
| Steam sterilisation of rooting media | Present (C052) |
| Hardening-off: sorting of plantlets | **Missing** (see [Still dropped](#chunks-still-dropped)) |

---

## Sentences removed

### DOC-002: 11, all false positives

All 11 are rows of *Table 9: Common Agro-Chemicals Used during Maize Production*. A product strength written as `g/l` reads as a mix ratio. These are **concentrations listed with trade names and registration numbers, not application doses.** They are of little use for a checklist, but they are recorded here as false positives.

| Row | Matched |
|---|---|
| ROCKETT 44EC Profenofos 400g/l + Cypermethrin 40g/l | `400g/l` |
| … 106g/l + Thiomethoxam 141g/l | `106g/l` |
| DUDU ALL 45EC Cypermethrin 100g/l + Chlorpyrifos 350g/l | `100g/l` |
| TAFGOR 40EC Dimethoate 400g/l | `400g/l` |
| DUDU CYPER 5EC Cypermethrin 50g/l | `50g/l` |
| Cypermethrin 50g/l | `50g/l` |
| OXFEN 24EC Oxfluorfen 240g/l | `240g/l` |
| WEED END 41SL Glyphosate 410g/l | `410g/l` |
| Glyphosate ammonium salt 757g/l | `757g/l` |
| WEED KILL 360 SL Glyphosate 360g/l (heading line) | `360g/l` |
| KALACH 360SL Glyphosate 360g/l | `360g/l` |

### DOC-003: 21 — 18 doses, 3 fertiliser

| Sentence (shortened) | Matched | Type |
|---|---|---|
| To stop fungal infections, apply a copper fungicide spray at a rate of 50gms of powder in 20 litres of water. | `50gms of powder in 20 litres` | Dose |
| Fumigation is achieved by using fungicides such as Metalaxyl 80g/Kg + Mancozeb-50 gms in 20litres of water or Carbendazim 500gms per litre… | `50 gms in 20litres` | Dose |
| …dip the cuttings in a solution made from 50gms of copper oxychloride in 20 litres of water. | `50gms of copper oxychloride in 20 litres` | Dose |
| ●●Insect pests and diseases in the rooting media can also be controlled using… cypermethrin at a rate of 2mls per litre… | `2mls per litre` | Dose |
| Spray a prepared solution of 50gms of fungicide in 20 litres of water. | `50gms of fungicide in 20 litres` | Dose |
| ●●Start by applying nutriplant… | `1 ml per liter` | Fertiliser |
| In case of an attack of leaf spot disease… a chemical spray of Carbenduzium… | `500 gms per litre` | Dose |
| This chemical is alternated with Metalaxyl mixed with Mancozeb at rates of 50 gms in 20 litres of water. | `50 gms in 20 litres` | Dose |
| Chemical control is fairly effective… Imidachloprid (80mls/20 litre water), Chloropyrifos ethyl (4mls/litre…) | `4mls/litre` | Dose |
| 70ml in 20litres of water or Pyrinex 1ml per litre of water. | `70ml in 20litres` | Dose |
| with 70 ml in 20 litres of water, Imidachloprid at a rate of 80 ml per 20 litre water. | `70 ml in 20 litres` | Dose |
| with 70 ml in 20litres of water or Pyrinex, 1ml per litre of water. | `70 ml in 20litres` | Dose |
| The pesticide is diluted at 4ml/L of water. | `4ml/L` | Dose |
| Another chemical Tebuconazole can be used at 6ml/L to kill the hatched larvae. | `6ml/L` | Dose |
| Chemical control of aphids involves the use of Cypermethrin at a rate of 70ml in 20 litres of water sprays. | `70ml in 20 litres` | Dose |
| …Damping-off disease can be controlled by the use of chemicals… Mancozeb at 50gms in 20 litre of water… | `50gms in 20 litre` | Dose |
| If the plants have been attacked use any of the three fungicides 500gms/litre of Carbenduzium… | `500gms/litre` | Dose |
| ●●After filling in the rooting media, the cages… (normally spray a prepared solution of 50gms of fungicide copper oxychloride in 20 litres…) | `50gms of fungicide copper oxychloride in 20 litres` | Dose |
| Insect pests in the rooting media can also be controlled using chemicals like Dursban, cypermethrin etc use 2mls per litre of water. | `2mls per litre` | Dose |
| Apply Nutrient plant or growth booster once every 14 days at rates of 1mls per litre of water. | `1mls per litre` | Fertiliser |
| ●●14 days after placement of cuttings, start applying nutriplant… | `1 ml per liter` | Fertiliser |

The 3 fertiliser removals are accepted under the decision in `phase-1-final-dosing-fix.md` not to exempt fertiliser.

The damping-off sentence (56 words) also carries the chapter title and page header before it, because the PDF text has no full stop between them.

---

## Chunks still dropped

These are dropped by the chunk-level backstop.

| Chunk | Words | Content | Judgement |
|---|---|---|---|
| DOC-002-C047 | 400 | Roundup calculation, sprayer calibration | Dropped as the brief intends: the amounts are spread across sentences |
| DOC-002-C048 | 203 | Roundup calculation, safe-mixing bullets | Dropped as the brief intends |
| DOC-003-C070 | 93 | Dursban drench, "apply 1 litre of the solution per Mother bush" | All dosing; nothing useful lost |
| DOC-003-C104 | 99 | Nursery budget table | Accepted collateral |
| **DOC-003-C114** | **351** | **12. Hardening off and acclimatisation** | **Genuinely useful and still lost** |

### DOC-003-C114 — the one useful loss

The chunk contains no pesticide dose. It trips the backstop because two unrelated signals sit in the same chunk:

- a fertiliser line: "use 700 gms of N.P.K. (17:17:17), mix it in a bucket/basin of water… Pour the prepared solution in a drum of **200 litres** of water"
- a passing reference: "apply the recommended **pesticides/Insecticides** as in Chapter 6"

Its plantlet-sorting and re-covering guidance ("Sorting of plantlets in the polypots begins at 3.5 to 4.5 months…") is the one checklist item from the brief's list that is still missing.

---

## Decisions on the open items

**Date:** 2026-09-29. Both items **declined**; no code or configuration changed.

| Item | Decision | Consequence |
|---|---|---|
| 1. Recover DOC-003-C114 (hardening-off) | **Declined** | The chunk stays dropped as accepted collateral. Its plantlet-sorting and re-covering guidance is not in the corpus. |
| 2. Exempt `g/l` product strengths in DOC-002 Table 9 | **Declined** | The 11 table rows stay removed as accepted false positives. |

Both losses remain recorded above, so the cost stays visible in the Phase 1 record.

---

## Related files

- `prompts/phase-1-sentence-level-removal.md` — the brief implemented here
- `prompts/final-dosing-fix-results.md` — previous phase results
- `prompts/chunk-filter-findings.md` — chunk filter results
- `prompts/corpus-findings-C01-C02.md` — heading fragmentation and the DOC-001 dosing gap
- `knowledge/corpus-register.md` — the generated Corpus Register
