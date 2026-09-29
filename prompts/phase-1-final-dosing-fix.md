# Phase 1 — Close the Remaining Dosing Gaps

One change plus one one-word fix. Then Phase 1 is finished.

## Decision on the open questions

1. **Do not add product names to the indicator list.** Maintaining a list of
   active ingredients is unbounded — the next manual will use a product this
   corpus does not contain. Use the structural signal below instead.
2. **Yes, add `mls` to the rate pattern.** One word, closes the
   `1.5mls per litre` miss.
3. **C104 and C114 are accepted collateral.** Record them; do not add an
   exception for fertiliser. See the reasoning at the end.

## The change — a mix-ratio trigger

All four surviving chunks share one construction: a quantity dissolved in a
volume of water.

- `Cypermethrin at a rate of 70ml in 20 litres of water`
- `Mancozeb at 50gms in 20 litre of water`
- `Carbendazim at a rate of 1.5mls per litre of water`
- `500gms/litre of Carbenduzium`

A quantity expressed *per litre* or *in N litres* is an application rate whatever
the substance is. That is a reliable structural signal and needs no vocabulary
list.

Add a second, **standalone** trigger to `chunkIsRestricted()`. It fires on its
own, with no chemical indicator required:

```php
private const MIX_RATIO_PATTERN =
    '/\b\d+(?:[.,]\d+)?\s*(?:ml|mls|g|gm|gms|gram|grams|kg|l|lt|lts|litre|litres|liter|liters)\s*'
    . '(?:\/\s*|per\s+|in\s+(?:\d+(?:[.,]\d+)?\s*)?)'
    . '(?:litre|litres|liter|liters|l|lt|lts)\b/iu';
```

`chunkIsRestricted()` now returns true when **either**:

- the existing two-signal rule matches (chemical indicator **and** a volume quantity), **or**
- `MIX_RATIO_PATTERN` matches.

Keep the existing rule. It catches the Roundup calculation, which states its
amounts across several sentences rather than as a single ratio.

## The one-word fix

In the line-level `RATE_PATTERN` from `phase-1-final-fix.md`, add `mls` to the
volume units alongside `ml`.

## Tests

Add to `RestrictedSectionStripperTest`:

1. `"Mancozeb at 50gms in 20 litre of water"` → restricted.
2. `"Cypermethrin at a rate of 70ml in 20 litres of water"` → restricted.
3. `"apply at a rate of 1.5mls per litre of water"` → restricted.
4. `"500gms/litre of Carbendazim"` → restricted.
5. `"Water the seedlings with 15 litres of water each morning"` → **not** restricted.
6. `"Recommended seed rate is 30 kg per acre"` → **not** restricted.

Tests 5 and 6 matter most: they confirm the pattern needs a quantity *and* a
per-litre denominator, so a plain water volume and a weight-per-area seed rate
both survive.

## Verification

```bash
php artisan test --filter=RestrictedSectionStripperTest
php artisan agrivisit:ingest --fresh
php artisan agrivisit:register
```

Then in `php artisan tinker`, confirm all four named gaps are gone:

```php
App\Models\Chunk::where('text','like','%Cypermethrin%')->orWhere('text','like','%Mancozeb%')->orWhere('text','like','%Carbend%')->orWhere('text','like','%per litre of water%')->orWhere('text','like','%in 20 litres%')->count()
```

Must return 0.

Then confirm the corpus is still usable:

```php
App\Models\Chunk::count()
```

If this falls below about 250, the filter is over-removing and needs review before
Phase 2.

## Why fertiliser volumes are not exempted

Exempting them would require deciding what is being dissolved, which reintroduces
the vocabulary problem the mix-ratio pattern exists to avoid. Fertiliser
application rates are also not needed for a field-visit checklist: the officer
inspects and asks, and rates come from a soil test and the product label. Losing
them costs little and keeps the rule explainable in one sentence.

## Report back

State chunks dropped per document, total chunks remaining, confirmation that the
tinker check returns 0, and any chunk lost that you judge genuinely useful for a
field-visit checklist.
