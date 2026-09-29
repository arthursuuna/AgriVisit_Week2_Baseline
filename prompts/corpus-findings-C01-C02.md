# Corpus Findings C-01 and C-02 — DOC-001 Investigation

**Document:** DOC-001, *Beans Training Manual for Extension Workers in Uganda* (MAAIF), 81 pages
**Date:** 2026-09-29
**Status:** C-02 resolved by `phase-1-final-fix.md` (see [Resolution](#resolution) at the end). C-01 accepted as-is. C-03 open. The findings below describe the corpus **before** that fix.

## How this was checked

- The chunks were queried from the database, and the PDF was re-extracted with the same `TextExtractor` the pipeline uses.
- The PDF pages could not be viewed as images (`pdftoppm` is not installed), so each heading was checked two other ways:
  1. **Table of contents:** the manual lists its real headings on pages 3–5. A heading listed there is genuine.
  2. **Surrounding text:** the raw lines around each heading show whether it sits inside a table, a list or a calculation.
- Candidate `excluded_headings` terms were tested **in memory only**. The config file was not changed.

---

## Summary

| Finding | Result |
|---|---|
| C-01 Fragmentation | About half of the lines `looksLikeHeading()` treats as headings are not headings. In a random sample of 10 chunks, only 5 had a genuine heading. |
| C-02 Nothing removed | The document contains one real dosing section (4.3.2), with a full Roundup dose calculation. It is in the corpus now as chunk `DOC-001-C060`. |
| Link between them | Adding the right term to `excluded_headings` does **not** remove the calculation, because a false heading inside the section ends the exclusion early. C-02 cannot be fixed without addressing C-01. |
| Possible C-03 | The PDF reader is losing text: 122 bullet lines come out cut off after a few characters. |

---

## C-01 — Fragmentation

141 chunks from 21,082 words: an average of 150 words against a 400-word target.

### Random sample of 10 chunks (seed `20260929`)

**5 of 10 headings are genuine.**

| Chunk | Words | Detected section | Verdict |
|---|---|---|---|
| C002 | 364 | `A` | ✗ Decorative large first letter of the Foreword ("**A**griculture is…") |
| C010 | 215 | `2.4.1 Timing of land preparation` | ✓ In the table of contents |
| C018 | 56 | `2.5.3 Steps in conducting Germination Test` | ✓ In the table of contents |
| C030 | 400 | `3.2.2 Categories of weeds` | ✓ In the table of contents |
| C042 | 142 | `Type of pest Damage Done Control Measures` | ✗ Table header row (p.29) |
| C105 | 116 | `• F` | ✗ Cut-off bullet point |
| C112 | 190 | `9.5: Farm record keeping` | ✓ In the table of contents. But the chunk's text is from 9.6 Resource mobilization. |
| C118 | 100 | `3. High input: 1,141,000 – 859,000 X100 =33 %` | ✗ Line of a worked calculation (p.66) |
| C121 | 53 | `2.Pay attention to seasonal` | ✗ Table cell (p.68) |
| C125 | 64 | `10.8: Mitigation practices on the farm` | ✓ In the table of contents |

### Chunk sizes

```
 40–99   69 ██████████████████████████████████
100–149  33 ████████████████
150–199  16 ████████
200–299  11 █████
300–399   6 ███
400       6 ███
```

- Median chunk: 100 words. Mean: 130 words.
- 123 distinct sections across 141 chunks.
- 111 sections produce exactly one chunk.

### The five shortest chunks, in full

1. **C059** (40 words). Section: `2. Fill the knapsack with known volume of water e.g 15 litres of water`, which is a numbered step, not a heading.
   > 3. Put the knapsack on your back and start pumping, walk at a steady walking pace, spraying with the nozzle at knee height and recite the word 'one thousand' over and over again making one pump stroke per 'one thousand'.

2. **C047** (41 words). Section: `Acephate, Dimethoate`, which is a wrapped table cell holding insecticide names.
   > Pod sucking bugs Bugs suck on pods causing tiny depressions (dimples), and may cause shrivelling and rotting of the seeds, which lose viability. Hand collect and kill when few are seen Well-timed (45 days after planting) application of insecticides such as

3. **C009** (42 words). Section: `2.4: Land Preparation` ✓. The heading is genuine; the section is just short before 2.4.1 starts.
   > Land preparation involves; bush clearing, removal of tree stamps, termite mounds, and ploughing. Beans require a fine seed bed for uniform and proper growth of roots to absorb the available soil nutrients. Well prepared seedbed reduces on the number of weeding times.

4. **C050** (42 words). Section: `Blight Disease: in`, which is a table-cell fragment.
   > seeds, plant debris, rain splash and by physical contact. •Has a distinct yellowing around the initial leaf spot, which spreads outwards, though generally, the symptoms are similar to those of common blight disease Control is as for common blight disease Bean Common

5. **C074** (42 words). Section: `5.2.7 Storage` ✓. Genuine and short.
   > Storage is the process of keeping grain until an appropriate time of use. The primary aim of storage is for quality maintenance, food & nutrition security, seed and better price. Note: Good Storage does not improve grain quality but it maintains it.

### Every detected heading in the document

`looksLikeHeading()` fires on 430 lines. Sorted into rough categories:

| Count | What the line is |
|---|---|
| ~200 | Numbered section headings such as `2.4.1 …` and `MODULE …`. These are real. Many are table-of-contents lines, so they produce no chunks. |
| 115 | Title-case or all-caps lines: table cells, labels, cover text, product names |
| 42 | Cut-off bullet points (`• W`, `• F`) |
| 40 | Numbered steps and table rows (`1. Measur`, `2. Fill the knapsack…`) |
| 22 | Lines with digits: calculations, toxicity classes, annex labels |
| 11 | One or two characters (`A`, `7.2`, `3. K`) |

The categories are approximate: a few genuine headings written as `3.1.` fall into the numbered-steps row.

### Cause

Three rules in `looksLikeHeading()` are too broad:

1. **The numbered rule** (`^\d+(\.\d+)*[\s.)-]+\S`) matches list steps such as `1.` and `2.`, not just section numbers such as `2.4.1`.
2. **The title-case rule** matches table cells and product names.
3. **The mostly-capitals rule** matches single letters: decorative first letters and cut-off bullets.

---

## C-02 — Nothing removed

The register shows zero sections cut from DOC-001.

### Term counts

Body text only; the table of contents is excluded.

| Term | Hits | Where most hits are |
|---|---|---|
| chemical | 36 | Module 4 (4.1–4.3.7) |
| spray | 33 | 4.3.2 (11), 4.3.5 During Spraying (7) |
| pesticide | 25 | 4.3.2 (5), 8.2 Specifications (4) |
| herbicide | 21 | 3.2.2 Categories of weeds (14) |
| rate | 21 | Mostly seed and manure rates (2.4.3, 2.5.3, 2.7) |
| insecticide | 8 | 3.4.1 Major Bean Insect Pests (6) |
| ml | 7 | 4.3.2 (6), 2.7 (1) |
| fungicide | 5 | 3.4.2 Major Bean Diseases (3) |
| dose | 4 | 4.3.2 (2), 3.2.2 (1), 5.3.3 Mycotoxins (1) |
| litres per | 0 | The manual writes "lts" |

### Hits by section

| Section | Terms |
|---|---|
| 2.4.1 Timing of land preparation | herbicide ×1, spray ×1 |
| 2.4.2 Method of land preparation and related equipment | herbicide ×2, spray ×1 |
| 2.4.3 Application of manure | rate ×1 |
| 2.5.2 Attributes of quality beans seed | rate ×1 |
| 2.5.3 Steps in conducting Germination Test | rate ×4 |
| 2.7 Spacing and Seed rate (population density) | rate ×3, spray ×1, ml ×1 |
| 2.9 Methods of Planting and Related Equipment | rate ×1 |
| 3.2 Weed Management | chemical ×2 |
| 3.2.2 Categories of weeds | herbicide ×14, chemical ×3, dose ×1, rate ×1 |
| 3.3.3 Signs of nutrient deficiency in beans | rate ×1 |
| 3.3.4.1 Organic manure | rate ×2 |
| 3.3.5 Other methods to manage soil fertility | herbicide ×1, spray ×1, rate ×1 |
| 3.4.1 Major Bean Insect Pests and their Management | insecticide ×6, pesticide ×1 |
| 3.4.2 Major Bean Diseases and Associated Control Measures | fungicide ×3 |
| 4.1 Agro-Chemicals | chemical ×1 |
| 4.1.1 Groups of Agro-Chemicals | chemical, herbicide, insecticide, fungicide ×1 each |
| 4.1.2 Advantages of using agro-chemicals | chemical ×5 |
| 4.2 Safe use of agro-chemicals | chemical ×2 |
| 4.2.2 Buying agro-chemicals | chemical ×3 |
| 4.2.3 Transporting the agro-chemicals | chemical ×1 |
| 4.2.4 Storing Agro-Chemicals | chemical ×3 |
| 4.3 Application of agro-chemicals | chemical ×1 |
| 4.3.1 Reading the Product label | rate ×1, chemical ×1 |
| **4.3.2 Determining how much pesticide to use** | **spray ×11, ml ×6, pesticide ×5, rate ×3, dose ×2, chemical ×1** |
| 4.3.3 Mixing Agro-Chemicals | chemical ×3, pesticide ×3, spray ×2, herbicide, fungicide, insecticide ×1 each |
| 4.3.5 During Spraying | spray ×7, chemical ×1, pesticide ×1 |
| 4.3.5 After spraying | spray ×3, pesticide ×2 |
| 4.3.6 Disposal of empty containers | spray ×1 |
| 4.3.7 Cleaning the spray pump and yourself | spray ×4 |
| 5.2.5 Methods of checking moisture content | rate ×1 |
| 5.2.7 Storage | chemical ×1 |
| 5.3.2 Control of pests | chemical ×3, pesticide ×1 |
| 5.3.3 Mycotoxins | dose ×1 |
| 7.6 Support services in beans marketing | chemical ×1 |
| 8.2 Specifications | chemical ×1, pesticide ×4 |
| 9.2 Principles of business | pesticide ×1, herbicide ×1 |
| 9.6 Resource mobilization and management | pesticide ×1 |
| 9.9 Cost Benefit Analysis (CBA) | pesticide ×1, spray ×1 |
| 10.5 Climate Smart Agriculture Practices on the farm | chemical ×1, rate ×1 |
| 10.8 Mitigation practices on the farm | chemical ×1, pesticide ×3 |

The manual numbers two sections "4.3.5"; both are listed as they appear.

### Where the dosing content actually is

Real dosing content appears in one place: **4.3.2 Determining how much pesticide to use** (p.37). It works through a full calculation:

> eg. Roundup is 1.5L (=1500ml) per Acre and an acre = 4,000 sq. metres
> 9. Using Round up at a rate of 1.5lts/acre, calculate the amount of chemical for a knap sack of 20lt capacity …
> Therefore a knapsack will require: 20lts x 1.5lts = 0.15lts of Roundup
> 0.15 x 1000ml = 150mls
> 10. Farmer can also calculate needed mls per litre of water = 150ml/20 = 7.5ml.

It is in the corpus now as chunk **DOC-001-C060**, filed under the false heading "8. Calculate the volume of water needed to spray an acre".

Everywhere else, the chemical terms appear in:
- general advice ("read the label", "apply agro-chemicals at recommended rates")
- safe-handling steps (Module 4.2 and 4.3.3–4.3.7)
- product names in the pest table (3.4.1)

None of these give amounts.

### Why nothing was removed

1. **No excluded term matches the heading.** "Determining how much pesticide to use" contains none of the configured terms. The closest, `pesticide application`, does not match.
2. **The rate pattern misses how this manual writes amounts:** `1.5lts/acre`, `1.5L (=1500ml) per Acre` and `150ml/20` all pass through.
3. **The rate pattern's only 5 removals were false positives**, stripping ordinary agronomy rather than dosing:
   - "Recommended seed rate = 30 kg per acre"
   - "The new seed rate will be 35.3 kg per acre …"
   - "average production of 0.25 tons (250kg) per acre. This is very low compared to potential yield of 700 to 1500kg/acre"
   - "variety as specified by scientist who developed it. E.g NABE 4 is 800 – 1000kg/acre, and NABE 12C is 1000 –"
   - "1400 kg/acre."

### Simulation (in memory; config not changed)

| Config | Sections cut | Lines removed | Dose figures still present |
|---|---|---|---|
| Current | 0 | 5 | 7.5ml, 1.5lts/acre, 1500ml, 150mls |
| + `how much pesticide` | 1 heading (4.3.2) | 21 | 7.5ml, 1.5lts/acre, 1500ml, 150mls |
| + `how much pesticide`, `mixing agro-chemicals` | 4.3.2 and 4.3.3 | 43 | 7.5ml, 1.5lts/acre, 1500ml, 150mls |

Adding the term cuts 4.3.2, but the removal stops at `1. Measur`, the first numbered step, which `looksLikeHeading()` treats as a new heading. Everything after it, including the whole Roundup calculation, survives.

### Recommendation

- **Add exactly one term**, taken from the document's own heading wording: **`how much pesticide`**.
- **Do not add `mixing agro-chemicals`.** Section 4.3.3 is safe-handling advice ("mix and fill outdoors", "open pesticide containers with extreme care") with no amounts. It is useful material for checklists.
- **The term alone is not enough.** It only works once one of the following is also done:
  - `looksLikeHeading()` stops treating numbered steps as headings (C-01), **or**
  - an excluded section runs until the next real numbered heading (here 4.3.3), not until any detected heading. This is the smaller change that closes C-02.
- **As a backstop, fix the rate pattern:**
  - cover `lts`, `L` and `ml/` forms such as `1.5lts/acre` and `150ml/20`
  - stop it removing seed rates and yield figures

---

## Possible C-03 — Text lost during PDF extraction

122 bullet lines come out as a fragment of six characters or fewer, for example `• Fallo`, `• Ensur`, `1. Measur`, `4. Spra` and `7. T`. The rest of each line is missing from the extracted text, not moved elsewhere, so those instructions are absent from the corpus.

This is separate from heading detection: better heading rules will not bring the text back. A different PDF reader may recover it.

---

## Decisions needed

1. **C-01:** tighten `looksLikeHeading()` (reject bullets, 1–2 character lines, numbered steps and table cells), or keep it and make excluded sections run until the next real numbered heading?
2. **C-02:** add `how much pesticide` to `excluded_headings`? It only works once decision 1 is made.
3. **Rate pattern:** fix its false positives on seed rates and yields, and its misses on `lts` and `ml/`?
4. **C-03:** log the text loss as a separate finding, and try a different PDF reader?

---

## Resolution

**Date:** 2026-09-29, implemented from `prompts/phase-1-final-fix.md`.

### Decisions taken

| Decision | Outcome |
|---|---|
| 1. C-01 | `looksLikeHeading()` **not changed**; C-01 accepted as-is and to be revisited only if Phase 3 retrieval underperforms. Instead, an excluded section now runs until the next real section heading. |
| 2. C-02 | `how much pesticide` **added** to `excluded_headings`. `mixing agro-chemicals` not added. |
| 3. Rate pattern | **Narrowed to volume units** and extended to this publisher's forms. |
| 4. C-03 | Not addressed; still open. |

### What changed

1. **`RestrictedSectionStripper::strip()`:** while a section is being excluded, only a genuine section heading ends the exclusion. Numbered steps such as `1. Measur` no longer end it. A genuine heading is a multi-level number followed by a capitalised title (`4.3.3 Mixing Agro-Chemicals`) or a `MODULE` line, and it must also pass `looksLikeHeading()`.
2. **`config/agrivisit.php`:** `'how much pesticide'` added to `excluded_headings`.
3. **Rate pattern:**
   - Units are now volume only: `ml`, `l`, `lt`, `lts`, `litre(s)`, `liter(s)`.
   - Weight units (`kg`, `g`, `grams`, `oz`, `lbs`) are dropped.
   - Forms like `1.5lts/acre` are now caught.
   - A plain-number denominator is also accepted, covering `150ml/20`.

### Where the implementation departed from the brief

- **Skip-end rule needs a capital letter.** The brief's regex `/^(\d+\.\d+|MODULE\b)/` also matches the calculation line `0.15 x 1000ml = 150mls`, which would have ended the skip there and let that line through.
- **Numeric denominator added.** As specified, the pattern does not match `150ml/20` (dividing by a 20-litre knapsack), so the brief's test 4 would not have passed without it.
- **Veterinary units dropped.** The brief's unit list leaves out `animal`, `head` and `bird`, so a stray "5 ml per animal" in a livestock document would now be kept. This does not affect DOC-001; worth restoring if livestock documents are added.

### Result on DOC-001

| | Before | After |
|---|---|---|
| Sections cut | 0 | 2 (the 4.3.2 heading in the table of contents and in the body) |
| Lines removed | 5 (all false positives) | 46, all inside 4.3.2 |
| Chunks | 141 | 138 |
| Words | 21,082 | 20,767 |
| Chunks containing `1.5lts`, `7.5ml` or `Roundup` | 1 (`DOC-001-C060`) | **0** |
| Chunks containing `seed rate` | — | **3** (seed-rate and yield lines now kept) |

### Legitimate content removed along with 4.3.2

Because the whole section is now cut, these went with the dose calculation:

- general advice: "Always read the label for recommended dilution rate", and why precise amounts matter (waste, crop damage, pest resistance)
- the knapsack sprayer calibration steps 1–6 (measure an area, fill with a known volume, pump at a steady pace, measure what is left). These contain no chemical amounts.
- a figure caption ("Common Knapsack Spray pump") and a page number

Nothing outside 4.3.2 was removed.

### Tests

- 4 tests added to `RestrictedSectionStripperTest`:
  - numbered steps do not end an excluded section, and the sections before and after it are kept
  - `Recommended seed rate = 30 kg per acre` is kept
  - `1.5lts/acre` is removed
  - `150ml/20 = 7.5ml` is removed
- The existing test sample was changed from single-level headings (`2. Chemical Control`) to multi-level (`2.2 Chemical Control`), and its stray rate from `kg` to `l`. Under the new rule, single-level lines are list steps, so the old sample no longer described a valid document.
- Results: 9 tests pass in `RestrictedSectionStripperTest`, and all 36 pass across the suite.

### Remaining risk

In a manual whose top-level headings are single-level (`3. Mulching Practice`), an excluded section would swallow the sections after it until the next `x.y` or `MODULE` heading. The removed heading and line count are shown in the register, so this would be visible.
