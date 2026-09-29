# Final Dosing Fix — Results

**Implemented from:** `prompts/phase-1-final-dosing-fix.md`
**Date:** 2026-09-29
**Follows:** `prompts/chunk-filter-findings.md` (the open gaps this fix closes)
**Status:** Complete. All checks pass. No pesticide or fungicide doses remain in the corpus. Phase 1 is finished per the brief.

---

## Summary

| Check | Result |
|---|---|
| Gap check: chunks containing `Cypermethrin`, `Mancozeb`, `Carbend`, `per litre of water` or `in 20 litres` | **0** (passes) |
| Total chunks | **290** (above the brief's review threshold of about 250) |
| Roundup check from the previous phase: `Roundup`, `150mls` or `0.15lts` | **0** (still passes) |
| Chunks containing "seed rate" | **5** (seed rates survive) |
| `RestrictedSectionStripperTest` | 20 tests pass (6 new) |
| Full test suite | 47 tests pass |

---

## Decisions applied

These answer the three questions left open in `chunk-filter-findings.md`.

| Question | Decision |
|---|---|
| 1. Add product names to the indicator list? | **No.** A list of active ingredients is unbounded. A structural mix-ratio trigger is used instead. |
| 2. Add `mls` to the rate pattern? | **Yes.** |
| 3. C104 (budget table) and C114 (hardening-off)? | **Accepted collateral.** Recorded here; no exception added for fertiliser. |

---

## What changed

1. **`RestrictedSectionStripper::MIX_RATIO_PATTERN` (new):** a quantity dissolved in a volume of water, such as `70ml in 20 litres`, `1.5mls per litre` or `500gms/litre`. The pattern is exactly as written in the brief:

   ```php
   '/\b\d+(?:[.,]\d+)?\s*(?:ml|mls|g|gm|gms|gram|grams|kg|l|lt|lts|litre|litres|liter|liters)\s*'
   . '(?:\/\s*|per\s+|in\s+(?:\d+(?:[.,]\d+)?\s*)?)'
   . '(?:litre|litres|liter|liters|l|lt|lts)\b/iu'
   ```

2. **`chunkIsRestricted()`:** now returns true when **either**:
   - `MIX_RATIO_PATTERN` matches (on its own, no chemical word needed), **or**
   - the existing rule matches: a chemical indicator **and** a volume quantity. This is kept for the Roundup calculation, which spreads its amounts across several sentences.

3. **Line-level `RATE_PATTERN`:** `mls` added alongside `ml`.

4. **`RestrictedSectionStripperTest`:** 6 new tests:
   - `Mancozeb at 50gms in 20 litre of water` → restricted
   - `Cypermethrin at a rate of 70ml in 20 litres of water` → restricted
   - `apply at a rate of 1.5mls per litre of water` → restricted
   - `500gms/litre of Carbendazim` → restricted
   - `Water the seedlings with 15 litres of water each morning` → **not** restricted
   - the line-level rate pattern now removes `1.5mls per litre`

   The brief's sixth test (`Recommended seed rate is 30 kg per acre` → not restricted) already existed from the previous phase with the identical string, so it was not added twice.

---

## Chunks per document

| Document | Pages | Words | Chunks | Avg words | Sections cut | Chunks dropped |
|---|---|---|---|---|---|---|
| DOC-001 Beans Training Manual | 81 | 20,767 | 138 | 150 | 2 | 0 |
| DOC-002 Maize Training Manual | 74 | 12,764 | 52 | 245 | 0 | 2 |
| DOC-003 Clonal Robusta Coffee Nursery Manual | 58 | 17,551 | 100 | 176 | 0 | **13** (was 9) |
| **Total** | | **51,082** | **290** (was 295) | | **2** | **15** (was 11) |

---

## The four gaps from the previous phase

| Gap (ID in the previous corpus) | Dose | Outcome |
|---|---|---|
| DOC-003-C059, rooted-cutting management | Carbendazim 1.5 mls per litre; Mancozeb 50 gms in 20 litres | **Dropped** |
| DOC-003-C083, aphid control | Cypermethrin 70 ml in 20 litres | **Dropped** |
| DOC-003-C087, damping-off | Mancozeb 50 gms in 20 litre; 1 ml per litre | **Dropped** |
| DOC-003-C090, leaf-spot control | Carbendazim 500 gms/litre | **Dropped** |

One earlier drop no longer appears in the drop list: the **6.1.3 mealybug chunk** (Imidacloprid 80 mls per 20 litres, Chlorpyrifos 4 mls per litre). The new `mls` rule now removes its dose lines before chunking. What remained was under the 40-word minimum, so it was discarded as a fragment.

---

## Every dropped chunk

IDs are the positions **before** filtering, from a dry run of the pipeline. Chunks marked *new* were dropped for the first time by this fix.

| Chunk | Words | Section | Content | Category |
|---|---|---|---|---|
| DOC-002-C047 | 400 | (toxicity table row) | Roundup calculation, sprayer calibration steps | Dosing plus legitimate material |
| DOC-002-C048 | 203 | (toxicity table row) | Roundup calculation, safe-mixing bullets | Dosing plus legitimate material |
| DOC-003-C044 | 297 | 3.2.10 Pre-harvest practices of suckers | Mother-garden sucker care; one copper-fungicide dose | Dosing plus legitimate material |
| DOC-003-C052 | 368 | 4.3.2 Treatment and handling of rooting media | Fumigation doses; steam sterilisation, cocopeat guidance | Dosing plus legitimate material |
| DOC-003-C058 | 179 | 4.5.2 Application of rooting hormone | Rooting hormone steps; one fungicide dose | Dosing plus legitimate material |
| DOC-003-C062 *new* | 366 | 4.5.5 Management of rooted cuttings | Watering schedules, cage management; Carbendazim and Mancozeb doses | Dosing plus legitimate material |
| DOC-003-C070 | 93 | Coffee nursery pests | Dursban drench | All dosing |
| DOC-003-C074 | 72 | 6.3.3 Caterpillar management and control | Fenitrothion, Imidacloprid doses | All dosing |
| DOC-003-C088 *new* | 65 | 6.8.3 Aphid management and control | Hand-picking, ant control; Cypermethrin dose | Dosing plus legitimate material |
| DOC-003-C092 *new* | 62 | Coffee diseases | Damping-off: Mancozeb and Carbendazim doses | All dosing |
| DOC-003-C095 *new* | 88 | 7.3.3 Leaf-spot management and control | Cultural controls; Carbendazim strength | Dosing plus legitimate material |
| DOC-003-C103 | 99 | (budget table) | Nursery cost table | **Accepted collateral**, no dose |
| DOC-003-C108 | 106 | 8. Placing in rooting media | Filling polypots; copper oxychloride fumigation | Dosing plus legitimate material |
| DOC-003-C111 *new* | 144 | 11. Management of cuttings under chambers | Weekly checks; nutrient booster at 1 mls per litre (fertiliser) | **Accepted collateral**, fertiliser only |
| DOC-003-C113 | 351 | 12. Hardening off and acclimatisation | Sorting, re-covering; NPK mix in 200 litres (fertiliser) | **Accepted collateral**, fertiliser only |

---

## Residual sweep

After re-ingesting, every chunk was searched for an `ml`, `mls`, `g`, `gms` or `grams` amount. **8 mentions remain, and none is a pesticide dose or a per-litre mix.** They are all fertiliser, lime or seed-inoculant amounts given per basin, per tree or per hectare:

| Chunk | Amount |
|---|---|
| DOC-001-C023 | Seed inoculant: "20 ml of the sticker solution" and "10 g" |
| DOC-002-C024 | Compound fertiliser "5-7 g per basin" |
| DOC-002-C031 (×2) | Fertiliser "100 kg/Ha using a bottle top of a 300 ml glass" |
| DOC-003-C029 | "50 gms of Diammonium Phosphate (DAP)" |
| DOC-003-C042 | "250 gms of dolomite per tree" |
| DOC-003-C057 | Nutriplant application |
| DOC-003-C062 | "700 gms of N.P.K" at hardening |

DOC-003-C073 (termite control) names "Dursban dust 2.5% EC, sprinkled around the cutting in the pot". That is a product strength with no amount, so it is not a dose.

---

## Useful checklist content lost

The coffee nursery manual is hit hardest: 13 of its 113 pre-filter chunks are gone. Most carried one dose sentence inside practical inspection guidance that a field-visit checklist would use:

| Section (DOC-003) | Useful content lost |
|---|---|
| 11. Management of cuttings under chambers | "Check for disease/pest infestation (once every week)", "Check the moisture/water in the system (once every week)". These are almost ready-made checklist items. |
| 7.3.3 Leaf-spot management and control | Open the cages for 2 hours in the early morning, avoid overwatering, keep 50% shade cover |
| 6.8.3 Aphid management and control | Hand-pick and crush aphids; get rid of the ants that protect them |
| 4.5.5 Management of rooted cuttings | Watering schedule by polypot size; keep the polythene cover airtight; open cages fortnightly to prevent leaf spot |
| 12. Hardening off and acclimatisation | When to sort plantlets; managing re-covering |
| 3.2.10, 4.5.2, 4.3.2 | Sucker care, rooting hormone steps, rooting media treatment |

---

## Suggested follow-up (not implemented)

Remove only the **sentence** that contains a mix ratio, rather than the whole chunk. The rule stays equally simple ("an amount per litre is removed"), and the surrounding inspection guidance survives. This was not implemented because the brief declares Phase 1 finished; it is worth revisiting if Phase 3 retrieval is weak on nursery management.

---

## Related files

- `prompts/week3-PHASE-1.md` — original Phase 1 brief
- `prompts/phase-1-bug-fix.md`, `prompts/phase-1-hardening.md`, `prompts/phase-1-final-fix.md`, `prompts/phase-1-chunk-filter.md`, `prompts/phase-1-final-dosing-fix.md` — follow-up briefs
- `prompts/corpus-findings-C01-C02.md` — heading fragmentation and the DOC-001 dosing gap
- `prompts/chunk-filter-findings.md` — chunk filter results and the gaps closed here
- `knowledge/corpus-register.md` — the generated Corpus Register
