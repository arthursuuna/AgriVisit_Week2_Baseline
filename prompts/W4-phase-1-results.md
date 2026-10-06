# Week 4 Phase 1 — The Tool Layer: Results

**Implemented from:** `prompts/IMPLEMENT-W4-PHASE-1.md`
**Date:** 2026-10-04
**Status:** **Complete and verified.** No model, API key or network involved.

| Part | State |
|---|---|
| Code (sections A–J) | Complete |
| New tests | **27 pass** (195 assertions) |
| Full suite | **112 pass** (367 assertions) |
| Migrations (`visits`, `followups`) | Run |
| `agrivisit:tools` / `--json` | Working |
| Tinker gate check | `APPROVAL_REQUIRED`, `Visit::count()` = `0` |

Nothing has been committed.

---

## What was built

### New files

| File | Section | Purpose |
|---|---|---|
| `app/Services/Tools/ToolResult.php` | A | Outcome value with the seven fixed failure codes, as given in the brief |
| `app/Services/Tools/ToolContract.php` | B | Tool interface, as given in the brief |
| `app/Services/Tools/SchemaValidator.php` | C | Validates arguments and output against the tool's own schema; accumulates every error |
| `app/Services/Tools/ToolRegistry.php` | D | The allow-list; duplicate names throw `LogicException` |
| `app/Services/Tools/ToolInvoker.php` | E | The gate — the only caller of `execute()` |
| `app/Services/Tools/ToolPolicy.php` | F | Reads `agrivisit.tools.enabled` on every call |
| `app/Services/Tools/ToolCallLog.php` | E.7 | One JSON line per attempt in `storage/app/tool-logs/YYYY-MM-DD.jsonl` |
| `app/Services/Tools/PreflightsArguments.php` | — | Optional interface for checks a schema cannot express (see Deviation 1) |
| `app/Services/Tools/Catalogue/FarmProfileLookupTool.php` | G | Read tool over `FarmProfileRepository` |
| `app/Services/Tools/Catalogue/ScheduleVisitTool.php` | G | Write tool; creates a `visits` row |
| `app/Services/Tools/Catalogue/LogFollowupTool.php` | G | Write tool; creates a `followups` row |
| `app/Models/Visit.php`, `app/Models/Followup.php` | H | Eloquent models |
| `database/migrations/2026_01_01_000006_create_visits_table.php` | H | `visits` table |
| `database/migrations/2026_01_01_000007_create_followups_table.php` | H | `followups` table, FK to `visits` with cascade |
| `app/Console/Commands/ListTools.php` | J | `php artisan agrivisit:tools [--json]` |
| `tests/Feature/SchemaValidatorTest.php` | 5 | 9 tests |
| `tests/Feature/ToolInvokerTest.php` | 5 | 9 tests |
| `tests/Feature/ToolCatalogueTest.php` | 5 | 9 tests |

### Changed files (additions only)

| File | Change |
|---|---|
| `config/agrivisit.php` | New `tools` block: `enabled` list and `log_path` |
| `app/Providers/AgriVisitServiceProvider.php` | Binds `ToolRegistry` (registers the three tools), `ToolCallLog`, `ToolInvoker` |

No file on the "Do not modify" list was touched.

---

## The gate (`ToolInvoker::invoke`)

| Step | Check | Result on failure |
|---|---|---|
| 1 | Name is registered | `TOOL_NOT_FOUND` |
| 2 | Arguments pass `inputSchema()` | `INVALID_ARGUMENTS` (all errors joined) |
| 3 | `ToolPolicy` allows the tool | `UNAUTHORIZED` |
| 4 | Preflight (tools implementing `PreflightsArguments`) | Tool's own code: `INVALID_ARGUMENTS` or `RESOURCE_NOT_FOUND` |
| 5 | Write tool without `$approved` | `APPROVAL_REQUIRED` with `pending = {tool, arguments}` |
| 6 | `execute()` inside try/catch | Any `Throwable` → `SERVICE_UNAVAILABLE` |
| 7 | Successful data passes `outputSchema()` | `UNEXPECTED_RESPONSE`, data discarded |
| — | Every attempt logged | `tool`, `registered`, `write`, `approved`, `arguments`, `outcome` (`OK` on success), `message`, `duration_ms`, `call_id` |

---

## Tool catalogue

Output of `php artisan agrivisit:tools`:

| Name | Access | Enabled | Required parameters | Description |
|---|---|---|---|---|
| `farm_profile_lookup` | read | yes | `farm_id` | Look up a farm by its ID to get its district, crops, last visit date, outstanding issues and previous advice. |
| `schedule_visit` | write (approval) | yes | `farm_id`, `visit_date`, `purpose` | Schedule a field visit to a farm on a given date; the officer must approve before it is booked. |
| `log_followup` | write (approval) | yes | `farm_id`, `visit_id`, `notes`, `outcome` | Record a follow-up note and outcome against an existing visit; the officer must approve before it is saved. |

`php artisan agrivisit:tools --json` prints, per tool: `name`, `access`, `requires_approval`, `enabled`, `description`, `input_schema`, `output_schema`. It is generated from the same schemas the invoker validates against, so it is the publishable Tool Catalogue.

### Input parameters

| Tool | Parameter | Rules |
|---|---|---|
| `farm_profile_lookup` | `farm_id` | string, required, `^UG-[A-Z]{3}-\d{3}$` |
| `schedule_visit` | `farm_id` | as above; farm must exist (`RESOURCE_NOT_FOUND`) |
| | `visit_date` | string, required, `format: date`; today or later (`INVALID_ARGUMENTS`) |
| | `purpose` | string, required, 1–200 characters |
| `log_followup` | `farm_id` | as above; must match the visit's farm (`INVALID_ARGUMENTS`) |
| | `visit_id` | string, required, `^V-\d{4,}$`; must exist (`RESOURCE_NOT_FOUND`) |
| | `notes` | string, required, 1–2000 characters |
| | `outcome` | enum: `resolved`, `ongoing`, `escalated` |

### Outputs

| Tool | Fields |
|---|---|
| `farm_profile_lookup` | `farm_id`, `district`, `crops` (array of `{crop, variety, area_acres, planted}`), `last_visit` (date or null), `outstanding_issues`, `previous_advice` |
| `schedule_visit` | `visit_id` (`V-0001`), `farm_id`, `visit_date`, `status` (`scheduled`) |
| `log_followup` | `entry_id` (`F-0001`), `farm_id`, `recorded_at` (ISO 8601) |

### Approval summaries

- `schedule_visit`: "Schedule a visit to {farm_id} on {visit_date}."
- `log_followup`: "Record a {outcome} follow-up note against visit {visit_id}."

---

## Tests

All run with no API key and no network.

**`SchemaValidatorTest`** — 9 pass

1. Valid object → no errors
2. Missing required property → named
3. Wrong type → names property, expected and received types
4. Unexpected property → reported
5. Value outside `enum` → reported
6. String failing `pattern` → reported
7. Malformed dates (`01/12/2026`, `2026-02-30`, `next tuesday`, `2026-1-5`) → reported
8. Seven problems in one call → seven errors
9. *(extra)* `maxLength` and nullable types

**`ToolInvokerTest`** — 9 pass

1. Unregistered name → `TOOL_NOT_FOUND`, nothing executed
2. Invalid arguments → `INVALID_ARGUMENTS`, tool never executed
3. Registered but disabled → `UNAUTHORIZED`
4. Write without approval → `APPROVAL_REQUIRED`, `visits` row count unchanged
5. Same call replayed from `pending` with approval → exactly one row
6. Throwing tool → `SERVICE_UNAVAILABLE`
7. Wrong-shaped output → `UNEXPECTED_RESPONSE`, data not passed on
8. All seven outcomes logged in order with tool, arguments and duration
9. *(extra)* Duplicate registration throws

**`ToolCatalogueTest`** — 9 pass

1. `farm_profile_lookup` known id → declared fields only
2. Unknown id → `RESOURCE_NOT_FOUND`
3. `schedule_visit` past date → `INVALID_ARGUMENTS`, with and without approval
4. `schedule_visit` approved → one row, `V-0001`, second call gets a different ref
5. `log_followup` unknown `visit_id` → `RESOURCE_NOT_FOUND`, with and without approval
6. Every registered schema well formed (`type: object`, non-empty `properties`, every `required` name exists, every property typed, every input property described)
7. *(extra)* `schedule_visit` unknown farm → `RESOURCE_NOT_FOUND`
8. *(extra)* `log_followup` held then approved → one entry `F-0001` linked to the visit
9. *(extra)* `log_followup` against another farm's visit → `INVALID_ARGUMENTS`

---

## Verification

```
php artisan migrate                       # visits, followups: DONE
php artisan test --filter=SchemaValidatorTest   # 9 passed
php artisan test --filter=ToolInvokerTest       # 9 passed
php artisan test --filter=ToolCatalogueTest     # 9 passed
php artisan agrivisit:tools               # table above
php artisan agrivisit:tools --json        # full schemas
```

Tinker:

```php
app(App\Services\Tools\ToolInvoker::class)->invoke('schedule_visit', ['farm_id' => 'UG-KYA-012', 'visit_date' => '2026-12-01', 'purpose' => 'Follow up leaf damage'])->code
// APPROVAL_REQUIRED
App\Models\Visit::count()
// 0
```

Resulting log line:

```json
{"recorded_at":"2026-10-03T23:06:26+00:00","call_id":"fd54ba60-…","tool":"schedule_visit","registered":true,"write":true,"approved":false,"arguments":{"farm_id":"UG-KYA-012","visit_date":"2026-12-01","purpose":"Follow up leaf damage"},"outcome":"APPROVAL_REQUIRED","message":"Schedule a visit to UG-KYA-012 on 2026-12-01.","duration_ms":24.51}
```

---

## Deviations and ambiguities in the brief

1. **Some checks have to run before approval.** With the gate order exactly as written, a past `visit_date` or an unknown `visit_id` would come back as `APPROVAL_REQUIRED`, so the officer would be asked to approve a call that can only fail. Added an optional `PreflightsArguments` interface; the invoker runs it after authorization and before approval. `ToolContract` is unchanged. Tools repeat the check inside `execute()` because an approved call may be replayed later. No design rule in section 2 is broken — preflight executes nothing.
2. **Validator keywords.** The brief's list omits length limits, but the tools need them (200 / 2000 characters). Added `minLength`/`maxLength`, `items` (array entries) and nullable types (`['string', 'null']`, needed for `last_visit`).
3. **`visit_id` has two meanings.** To the model it is the reference string (`V-0001`); in the `followups` table it is the integer foreign key to `visits.id`. Implemented as the brief specifies, but easy to confuse.
4. **Checks not in the brief:**
   - `schedule_visit` refuses an unknown farm with `RESOURCE_NOT_FOUND`.
   - `log_followup` refuses a visit belonging to a different farm with `INVALID_ARGUMENTS`.
5. **References** (`V-0001`, `F-0001`) come from the row id inside a transaction, not a row count, so deleting rows cannot produce a duplicate.
6. **Lookup output is projected.** `farm_profile_lookup` returns only declared fields; farmer alias, subcounty, soil and water notes are dropped, otherwise output validation would reject them.
7. **Summary grammar.** The template yields "Record a escalated follow-up note…". Kept the brief's wording.
8. **For Phase 2:** Gemini function declarations may not accept `format: date` or array types such as `["string","null"]`. Only input schemas go to the model and they use no null types, but `format: date` will likely need removing or translating when declarations are generated.
9. **Timezone.** `app.timezone` is UTC, so between 00:00 and 03:00 Kampala time "today" is still the previous date for the past-date check. Consider `Africa/Kampala`.
