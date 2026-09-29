# Phase 1 — Chunk-Level Dosing Filter

A second dosing calculation survived ingestion. Fix it with a content-level
check. Do not touch `looksLikeHeading()`.

## What was found

`DOC-002-C047` and `DOC-002-C048` (MAAIF Maize Training Manual) contain a full
pesticide dose calculation — the same Roundup worked example that was removed
from DOC-001, reproduced in a different manual:

> "How much pesticide (ml) do I put in a knapsack (20lts)?" … Roundup is 1.5L
> (=1500ml) per Acre … a knapsack of 20 lts of water (20x1.5)/200 = 0.15lts of
> Roundup … 0.15lts x 1000ml = 150mls

Both existing defences missed it:

1. **Heading exclusion failed** because there is no heading to match. The
   detected section is `"IV GREEN 317 - C Handle with care"`, a row from a
   pesticide toxicity-classification table. This is why DOC-002 reported zero
   sections cut.
2. **The rate pattern failed** because this manual expresses amounts without a
   denominator: `0.15lts of Roundup`, `150mls`, `1.5 Liters of Round up`. None
   of these look like a rate.

The lesson is structural: heading-based removal depends on the publisher using a
heading, and a table or a question-and-answer block has none.

## The fix — a chunk-level check after chunking

Add a method to `RestrictedSectionStripper`:

```php
public function chunkIsRestricted(string $text): bool
```

It returns true when the chunk contains **both**:

**(a) a chemical indicator** — case-insensitive match on any of:
`pesticide`, `insecticide`, `herbicide`, `fungicide`, `agro-chemical`,
`agrochemical`, `roundup`, `round up`, `knapsack`, `sprayer`, `spray pump`,
`dilution`

**(b) a quantity with a volume unit** — regex:

```php
/\b\d+(?:[.,]\d+)?\s*(?:ml|mls|l|lt|lts|litre|litres|liter|liters)\b/iu
```

Either alone is fine. Safe-handling advice with no numbers stays. A water volume
with no chemical context stays. Only the combination is removed.

Apply it in `IngestCorpus` after `Chunker::chunk()` and before persisting: skip
any chunk where this returns true, and count the skips.

## Recording it

Add a migration adding `chunks_dropped` (unsigned integer, default 0) to
`documents`. Set it during ingestion, show it as a column in the ingest summary
table, and add it to the Corpus Register with a short explanatory line:

> Chunks containing both a chemical indicator and a measured volume are dropped
> at ingestion, so dosing content cannot be retrieved even where it appears
> outside a recognised section heading.

## Accepted cost

This will remove some legitimate content — sprayer calibration steps that mention
water volumes, and safe-handling passages that quote a container size. That is the
right trade: the AI Boundary Matrix forbids dosing advice absolutely, and
calibration guidance is not required for a field-visit checklist. Report the count
so the cost is visible rather than silent.

## Tests

Add to `RestrictedSectionStripperTest`:

1. The text `"Roundup is 1.5L (=1500ml) per Acre"` → restricted.
2. The text `"0.15lts x 1000ml = 150mls"` with `knapsack` in the same chunk → restricted.
3. `"Always read the label carefully and understand the instruction"` → **not** restricted (chemical context, no quantity).
4. `"Fill the container with 15 litres of water before planting"` → **not** restricted (quantity, no chemical indicator).
5. `"Recommended seed rate is 30 kg per acre"` → **not** restricted.

## Verification

```bash
php artisan migrate
php artisan test --filter=RestrictedSectionStripperTest
php artisan agrivisit:ingest --fresh
php artisan agrivisit:register
```

Then, in `php artisan tinker`:

```php
App\Models\Chunk::where('text','like','%Roundup%')->orWhere('text','like','%150mls%')->orWhere('text','like','%0.15lts%')->count()
```

Must return 0.

Also check the four DOC-003 chunks previously matching `ml per` or `litres per`
(`DOC-003-C052`, `C075`, `C093`, `C112`) and report whether they were dropped and
what they contained.

## Report back

State chunks dropped per document, confirm the tinker check returns 0, and list
anything clearly legitimate that was removed so the cost can be recorded.
