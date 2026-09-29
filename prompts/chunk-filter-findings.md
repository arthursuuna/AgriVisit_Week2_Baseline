# Chunk-Level Dosing Filter — Results and Open Gaps

**Implemented from:** `prompts/phase-1-chunk-filter.md`
**Date:** 2026-09-29
**Corpus:** DOC-001 (MAAIF Beans Training Manual), DOC-002 (MAAIF Maize Training Manual), DOC-003 (UCDA/MAAIF Clonal Robusta Coffee Nursery Manual)
**Status:** Implemented as specified. The brief's dose check passes, but **four chunks in DOC-003 still contain pesticide or fungicide doses.** The filter has not been widened beyond the brief; decisions are listed at the end.

---

## Summary

| Check | Result |
|---|---|
| Chunks containing `Roundup`, `150mls` or `0.15lts` | **0** (passes) |
| Chunks dropped | DOC-001: 0, DOC-002: 2, DOC-003: 9 (11 in total) |
| `RestrictedSectionStripperTest` | 14 tests pass (5 new) |
| Full test suite | 41 tests pass |
| Dosing still in the corpus | **4 chunks in DOC-003** (see [Open gaps](#open-gaps)) |
| Legitimate content with no dose that was dropped | 2 chunks: a budget table and the hardening-off section |

### Ingest summary after the change

| Ref | Pages | Words | Chunks | Avg words | Min/Max | Sections cut | Chunks dropped |
|---|---|---|---|---|---|---|---|
| DOC-001 | 81 | 20,767 | 138 | 150 | 41/400 | 2 | 0 |
| DOC-002 | 74 | 12,764 | 52 | 245 | 40/400 | 0 | 2 |
| DOC-003 | 58 | 17,613 | 105 | 168 | 41/400 | 0 | 9 |

The corpus now holds 3 documents and 295 chunks.

---

## What changed

1. **`RestrictedSectionStripper`:** a new `chunkIsRestricted(string $text): bool`, which returns true when a chunk contains both:
   - a chemical indicator: `pesticide`, `insecticide`, `herbicide`, `fungicide`, `agro-chemical`, `agrochemical`, `roundup`, `round up`, `knapsack`, `sprayer`, `spray pump`, `dilution`
   - a quantity with a volume unit: `/\b\d+(?:[.,]\d+)?\s*(?:ml|mls|l|lt|lts|litre|litres|liter|liters)\b/iu`
2. **`IngestCorpus`:**
   - filters chunks after `Chunker::chunk()` and before they are saved, and stores the count as `chunks_dropped`
   - the summary table has a new "Chunks dropped" column
   - chunk IDs are numbered after filtering, so they stay consecutive with no gaps
3. **Migration `2026_01_01_000003_add_chunks_dropped_to_documents_table`:** adds `documents.chunks_dropped` (unsigned integer, default 0).
4. **`BuildCorpusRegister`:** adds the dropped count to the totals row, a "Chunks dropped" column in the Documents table, and a "Chunks dropped at ingestion" section with the brief's explanatory line.
5. **`RestrictedSectionStripperTest`:** 5 new tests:
   - "Roundup is 1.5L (=1500ml) per Acre" → restricted
   - "0.15lts x 1000ml = 150mls" with "knapsack" in the same chunk → restricted
   - "Always read the label carefully…" → not restricted
   - "Fill the container with 15 litres of water before planting" → not restricted
   - "Recommended seed rate is 30 kg per acre" → not restricted

---

## The four DOC-003 chunks named in the brief

Chunk IDs shifted after re-ingesting, so each chunk was traced by a phrase from its text.

| Before | Content | Outcome |
|---|---|---|
| C052 | Rooting-media fumigation: Mancozeb 50 gms in 20 litres, cypermethrin 2 mls per litre | Dropped |
| C075 | Caterpillar control: Fenitrothion 70 ml in 20 litres, Imidacloprid 80 ml per 20 litres | Dropped |
| C093 | Damping-off: Mancozeb 50 gms in 20 litres, 1 ml per litre of water | **Kept, now `DOC-003-C087`.** It says "chemicals", never "fungicide", so the filter sees no chemical context. |
| C112 | Nutrient booster at 1 ml per litre; NPK 250 gms in 200 litres | Kept. This is fertiliser, which is the intended outcome. |

---

## What the filter dropped (the cost)

### All dosing — nothing legitimate lost

| Chunk (before filter) | Content |
|---|---|
| DOC-003-C070 | Imidacloprid 80 mls per 20 litres, Chlorpyrifos 4 mls per litre, Dursban drench |
| DOC-003-C071 | Dursban in 2 litres of water, applied at 1 litre per mother bush |
| DOC-003-C075 | Fenitrothion 70 ml in 20 litres, Imidacloprid 80 ml per 20 litres |

### Dosing plus legitimate material

| Chunk (before filter) | Words | Dose | Legitimate content lost with it |
|---|---|---|---|
| DOC-002-C047 | 400 | The Roundup calculation | Sprayer calibration steps and "read the label" advice |
| DOC-002-C048 | 203 | The Roundup calculation | Safe-mixing bullets: "mix and fill outdoors", "never use hands as scoops", "open containers with extreme care" |
| DOC-003-C044 | 297 | Copper fungicide, 50 gms in 20 litres | Mostly pre-harvest care of suckers in a mother garden |
| DOC-003-C052 | 377 | Several fungicide and insecticide doses | Steam sterilisation and cocopeat rooting-media guidance |
| DOC-003-C058 | 179 | Copper oxychloride, 50 gms in 20 litres | How to apply rooting hormone |
| DOC-003-C109 | 114 | Copper oxychloride fumigation; Dursban 2 mls per litre | Filling polypots and placing rooting media |

### Clearly legitimate — no dose at all

| Chunk (before filter) | Words | Content | What triggered it |
|---|---|---|---|
| DOC-003-C104 | 99 | Nursery **budget table** | A "Pesticides/Insecticides" cost line and "6 drums @ 200 litres" |
| DOC-003-C114 | 351 | **Hardening-off and acclimatisation** of plantlets | A fertiliser mix ("700 gms of N.P.K … 200 litres of water") and a passing "apply the recommended pesticides as in Chapter 6" |

---

## Open gaps

### Dosing that still gets through

| Chunk | Words | Dose it contains | Why the filter misses it |
|---|---|---|---|
| DOC-003-C059 | 383 | Carbendazim "at a rate of 1.5mls per litre of water"; Metalaxyl with Mancozeb "at rates of 50 gms in 20 litres of water" | Says "chemical spray", which is not on the indicator list |
| DOC-003-C083 | 65 | "Cypermethrin at a rate of 70ml in 20 litres of water" | Named only by product ("Cypermethrin") |
| DOC-003-C087 | 62 | Mancozeb "at 50gms in 20 litre of water"; "1ml per litre of water" | Says "chemicals", not "fungicide" |
| DOC-003-C090 | 88 | "500gms/litre of Carbenduzium + 56g/ litre of ethylene" | Has "fungicides", but the amount is grams per litre, which the volume check does not recognise. This is closer to a product strength than an application rate. |

### Two misses by the line-level rate check

These come from the rate pattern added in `phase-1-final-fix.md`:

- **`1.5mls per litre`** (C059): the rate pattern does not include the plural `mls`.
- **`1ml per litre`** (C087): probably split across two lines in the PDF, and the rate check only looks at one line at a time.

---

## Decisions needed

1. **Widen the filter?** To catch the four remaining chunks:
   - the indicator list would need `chemical`, `spray`, and product names: `cypermethrin`, `mancozeb`, `metalaxyl`, `carbendazim`, `dursban`, `chlorpyrifos`, `imidacloprid`, `fenitrothion`, `copper oxychloride`
   - the quantity check would need grams-per-litre forms (`500gms/litre`, `50 gms in 20 litres`)

   This would drop more legitimate text: C059 alone is 383 words, mostly watering schedules.
2. **Add `mls` to the rate pattern?** A one-word fix for the `1.5mls per litre` miss.
3. **C104 and C114:** record them as accepted collateral, or stop fertiliser-only volumes from triggering the check?
