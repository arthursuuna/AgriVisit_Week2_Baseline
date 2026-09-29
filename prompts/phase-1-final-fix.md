# Phase 1 Final Fix — Close the Dosing Gap

Three small changes. Do only these. Do **not** modify `looksLikeHeading()` —
finding C-01 is accepted as-is and will be revisited only if Phase 3 retrieval
underperforms.

## Why

Chunk `DOC-001-C060` currently holds a worked pesticide dose calculation
("Roundup at a rate of 1.5lts/acre", "7.5ml per litre of water"). The AI
Boundary Matrix forbids the system from producing dosing advice, and retrieval
could supply this chunk to the model as evidence. It must not be in the corpus.

## Change 1 — an excluded section runs until the next real section heading

In `RestrictedSectionStripper::strip()`, once `$skipping` is true it is currently
cleared by *any* line that `looksLikeHeading()` accepts. Because numbered steps
such as `1. Measur` pass that test, an excluded section ends after one line and
the rest survives.

While skipping, only end the skip on a genuine section heading: a line matching

```php
/^(\d+\.\d+|MODULE\b)/u
```

that is, a multi-level section number such as `4.3.3`, or a `MODULE` heading.
Single-level numbers (`1.`, `2.`) are list steps and must not end a skip.

Behaviour to preserve: when not skipping, heading detection is unchanged.

## Change 2 — add one excluded heading

Add exactly one term to `excluded_headings` in `config/agrivisit.php`, taken from
this document's own wording:

```php
'how much pesticide',
```

Do **not** add `mixing agro-chemicals`. Section 4.3.3 is safe-handling guidance
with no amounts, and is useful checklist material.

## Change 3 — narrow the rate pattern to volume units

`RestrictedSectionStripper::RATE_PATTERN` currently removes weight-per-area
figures, which in agronomy are almost always seed rates and yields. It stripped
five legitimate lines from this manual ("Recommended seed rate = 30 kg per acre",
"potential yield of 700 to 1500kg/acre") while missing every chemical amount.

Restrict the pattern to volume units, which in this domain indicate chemical
application, and extend it to cover the forms this publisher uses (`lts`, `L`,
`1.5lts/acre`):

- units: `ml`, `l`, `lt`, `lts`, `litre(s)`, `liter(s)`
- denominators: `l`, `litre`, `liter`, `ha`, `hectare`, `acre`, `knapsack`, `tank`, `plant`, `tree`
- allow no space before the unit (`1.5lts/acre`) and `per` or `/` between

Drop `kg`, `g`, `grams`, `oz`, `lbs` from the pattern entirely.

## Tests

Add to `RestrictedSectionStripperTest`:

1. An excluded heading followed by numbered steps removes **all** the steps, and
   stops only at the next `\d+\.\d+` heading.
2. `Recommended seed rate = 30 kg per acre` is **kept**.
3. `Using Round up at a rate of 1.5lts/acre` is **removed**.
4. `Farmer can also calculate needed mls per litre of water = 150ml/20 = 7.5ml`
   is **removed**.

## Verification

```bash
php artisan test --filter=RestrictedSectionStripperTest
php artisan agrivisit:ingest --fresh
php artisan agrivisit:register
```

Then prove the gap is closed:

```bash
php artisan tinker
>>> App\Models\Chunk::where('text','like','%1.5lts%')->orWhere('text','like','%7.5ml%')->orWhere('text','like','%Roundup%')->count()
```

This must return **0**. The register must show a non-zero "sections cut" for
DOC-001.

Also confirm the seed-rate lines survived:

```bash
>>> App\Models\Chunk::where('text','like','%seed rate%')->count()
```

This must be greater than 0.

## Report back

Confirm the two tinker checks, state how many sections were cut and how many
lines removed, and flag anything legitimate that the wider section removal took
with it.
