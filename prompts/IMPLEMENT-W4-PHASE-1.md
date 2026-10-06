# Implement Week 4 Phase 1 — The Tool Layer

Build the deterministic tool layer. **No model is involved in this phase.**
Everything here must be testable with no API key, no network and no quota.

## 1. Context

AgriVisit is a Laravel application that drafts field-visit checklists for Ugandan
agricultural extension officers. Week 3 added retrieval: 301 chunks from three
manuals, cited in every drafted item. Week 4 lets the model call software
capabilities instead of only producing text.

This phase builds the capabilities and the gate in front of them. Phase 2 wires
them to the model, Phase 3 adds the approval flow, Phase 4 gathers failure
evidence.

### Do not modify

`app/Services/Llm/`, `app/Services/Embedding/`, `app/Services/Retrieval/`,
`app/Services/Corpus/`, `resources/prompts/`, `ChecklistDrafter`,
`ChecklistParser`, `RestrictedTopicGuard`.

### May add to, not rewrite

`config/agrivisit.php` (a new `tools` block) and `AgriVisitServiceProvider`
(new bindings inside the existing `register()`).

## 2. Design rules

1. **One schema, two uses.** Each tool declares its input schema once. The same
   declaration generates the model's function declaration in Phase 2 and
   validates arguments at runtime. Two copies drift, and the drift shows up as
   the model being told about a parameter the validator rejects.
2. **Nothing executes without passing the gate.** Tools are never called
   directly by application code. Every call goes through `ToolInvoker`, which
   checks the allow-list, validates arguments, checks authorization, executes,
   then validates the output.
3. **Write tools do not execute on request.** A tool that changes state returns
   `APPROVAL_REQUIRED` unless the call carries an explicit approval. Phase 3
   builds the officer-facing flow; this phase builds the mechanism.
4. **Every outcome is a value, not an exception.** A tool that fails returns a
   failed `ToolResult` with a machine-readable code. Exceptions are for
   programmer error only.
5. **Output is validated too.** A tool returning the wrong shape is a failure,
   not a partial success. This is what makes "unexpected tool responses"
   testable in Phase 4.
6. **Every attempt is logged**, including refused ones, with the tool name, the
   arguments, the outcome code and the duration.

## 3. Failure codes

Define these as constants on `ToolResult`. Phase 4's evidence table is built
from them, so the set must be fixed now.

| Code | Meaning |
|---|---|
| `TOOL_NOT_FOUND` | The requested name is not on the allow-list |
| `INVALID_ARGUMENTS` | Arguments failed input-schema validation |
| `UNAUTHORIZED` | The caller is not permitted to use this tool |
| `APPROVAL_REQUIRED` | A write tool was called without approval |
| `RESOURCE_NOT_FOUND` | The tool ran but its subject does not exist |
| `SERVICE_UNAVAILABLE` | A dependency the tool needs could not be reached |
| `UNEXPECTED_RESPONSE` | The tool returned data failing its own output schema |

## 4. Files to create

### A. `app/Services/Tools/ToolResult.php`

```php
<?php

namespace App\Services\Tools;

/**
 * The outcome of one tool invocation.
 *
 * Always a value, never an exception. The model, the orchestrator and the
 * evidence table all read the same codes.
 */
final class ToolResult
{
    public const TOOL_NOT_FOUND      = 'TOOL_NOT_FOUND';
    public const INVALID_ARGUMENTS   = 'INVALID_ARGUMENTS';
    public const UNAUTHORIZED        = 'UNAUTHORIZED';
    public const APPROVAL_REQUIRED   = 'APPROVAL_REQUIRED';
    public const RESOURCE_NOT_FOUND  = 'RESOURCE_NOT_FOUND';
    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    public const UNEXPECTED_RESPONSE = 'UNEXPECTED_RESPONSE';

    private function __construct(
        public readonly bool $ok,
        public readonly array $data,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly ?array $pending,
    ) {
    }

    public static function success(array $data): self
    {
        return new self(true, $data, null, null, null);
    }

    public static function failure(string $code, string $message): self
    {
        return new self(false, [], $code, $message, null);
    }

    /**
     * A write tool that would succeed, held for officer approval.
     * $pending carries the tool name and arguments so the action can be
     * replayed verbatim once approved.
     */
    public static function awaitingApproval(string $tool, array $args, string $summary): self
    {
        return new self(false, [], self::APPROVAL_REQUIRED, $summary,
            ['tool' => $tool, 'arguments' => $args]);
    }

    public function toArray(): array
    {
        return [
            'ok'      => $this->ok,
            'data'    => $this->data,
            'code'    => $this->code,
            'message' => $this->message,
        ];
    }
}
```

### B. `app/Services/Tools/ToolContract.php`

```php
<?php

namespace App\Services\Tools;

interface ToolContract
{
    /** snake_case, stable. This is the name the model calls. */
    public function name(): string;

    /** One sentence the model reads to decide whether this tool applies. */
    public function description(): string;

    /** JSON-Schema-style: ['type' => 'object', 'properties' => [...], 'required' => [...]] */
    public function inputSchema(): array;

    /** Same shape. Used to validate what the tool returns. */
    public function outputSchema(): array;

    /** True when the tool changes state and therefore needs officer approval. */
    public function isWrite(): bool;

    /** A one-line description of what approving this call would do. */
    public function approvalSummary(array $args): string;

    /** Arguments are already validated when this is called. */
    public function execute(array $args): ToolResult;
}
```

### C. `app/Services/Tools/SchemaValidator.php`

Deliberately small. Support only what the tools need: `object`, `string`,
`integer`, `number`, `boolean`, `array`; `required`; `enum`; `pattern`;
`minimum`/`maximum`; and `format: date` (expects `Y-m-d` and must parse).

```php
public function validate(array $schema, array $value): array  // returns list of error strings, empty when valid
```

Rules:

- An unknown property that is not in `properties` is an error
  ("unexpected parameter"). The model must not be able to smuggle extra fields.
- A missing required property is an error naming the property.
- A type mismatch is an error naming the property, the expected type and what
  was received.
- Errors accumulate. Return all of them, not just the first — Phase 4 evidence
  is clearer when a single call reports every problem.

### D. `app/Services/Tools/ToolRegistry.php`

```php
public function register(ToolContract $tool): void;
public function has(string $name): bool;
public function get(string $name): ?ToolContract;
/** @return list<ToolContract> */
public function all(): array;
```

Registering a duplicate name throws — a silent overwrite would let one tool
shadow another.

### E. `app/Services/Tools/ToolInvoker.php`

The gate. This is the only thing that calls `execute()`.

```php
public function invoke(string $name, array $args, bool $approved = false): ToolResult
```

In order:

1. **Allow-list.** `TOOL_NOT_FOUND` if the registry does not have it. Log the
   attempt — a call to an unregistered tool is the signal Story 11 is about.
2. **Input validation.** `INVALID_ARGUMENTS` with every error message joined.
3. **Authorization.** Consult `ToolPolicy` (F). `UNAUTHORIZED` if refused.
4. **Approval.** If `isWrite()` and not `$approved`, return
   `ToolResult::awaitingApproval(...)`. Do not execute.
5. **Execute** inside try/catch. Any `Throwable` becomes
   `SERVICE_UNAVAILABLE` with the exception message — a tool must never take
   down the request.
6. **Output validation.** If the tool succeeded but its data fails
   `outputSchema()`, discard the data and return `UNEXPECTED_RESPONSE`.
7. **Log** the attempt in all cases: tool, arguments, outcome code, duration in
   milliseconds. Reuse `TraceWriter` or write to `storage/app/tool-logs/`.

### F. `app/Services/Tools/ToolPolicy.php`

Keep it simple and explicit. A config-driven allow-list of which tools are
enabled, so a tool can be disabled without deleting it:

```php
public function allows(ToolContract $tool): bool;
```

Reads `config('agrivisit.tools.enabled')`, a list of tool names. A registered but
not-enabled tool returns `UNAUTHORIZED`, which is the condition Phase 4 tests.

### G. The three tools — `app/Services/Tools/Catalogue/`

**`FarmProfileLookupTool`** — read.

- input: `farm_id` (string, required, pattern `^UG-[A-Z]{3}-\d{3}$`)
- output: `farm_id`, `district`, `crops` (array), `last_visit` (string or null),
  `outstanding_issues` (array), `previous_advice` (array)
- reads the existing `FarmProfileRepository`
- unknown id → `RESOURCE_NOT_FOUND`

**`ScheduleVisitTool`** — write.

- input: `farm_id` (required, same pattern), `visit_date` (required, format
  date), `purpose` (string, required, max 200 characters)
- output: `visit_id` (string), `farm_id`, `visit_date`, `status` (enum:
  `scheduled`)
- creates a row in a new `visits` table
- rejects a `visit_date` in the past → `INVALID_ARGUMENTS`
- `approvalSummary()`: "Schedule a visit to {farm_id} on {visit_date}."

**`LogFollowupTool`** — write.

- input: `farm_id` (required), `visit_id` (required), `notes` (string, required,
  max 2000), `outcome` (enum: `resolved`, `ongoing`, `escalated`)
- output: `entry_id`, `farm_id`, `recorded_at`
- creates a row in a new `followups` table
- unknown `visit_id` → `RESOURCE_NOT_FOUND`
- `approvalSummary()`: "Record a {outcome} follow-up note against visit {visit_id}."

### H. Migrations

`visits`: id, `visit_ref` (unique, `V-0001`), `farm_id`, `visit_date` (date),
`purpose`, `status`, `created_by` nullable, timestamps.

`followups`: id, `entry_ref` (unique, `F-0001`), `visit_id` (foreign key,
cascade), `farm_id`, `notes` (text), `outcome`, `recorded_at`, timestamps.

Derive references from the row count at insert time inside a transaction, or
from the id — do **not** use a running counter held in the command. That bug
already cost a fix in Week 3.

### I. Config block

```php
'tools' => [
    'enabled' => [
        'farm_profile_lookup',
        'schedule_visit',
        'log_followup',
    ],
    'log_path' => storage_path('app/tool-logs'),
],
```

### J. `app/Console/Commands/ListTools.php`

`php artisan agrivisit:tools` prints the catalogue: name, read or write,
description, required parameters, and whether it is currently enabled.

`php artisan agrivisit:tools --json` prints the full schemas. This output
becomes the Tool Catalogue deliverable, so it must be complete enough to publish.

## 5. Tests — `tests/Feature/`

All of these run with no API key and no network.

**`SchemaValidatorTest`**

1. A valid object returns no errors.
2. A missing required property is reported, naming it.
3. A wrong type is reported, naming the property and both types.
4. An unexpected property is reported.
5. A value outside an `enum` is reported.
6. A string failing a `pattern` is reported.
7. A malformed date under `format: date` is reported.
8. Multiple problems in one call return multiple errors.

**`ToolInvokerTest`**

1. An unregistered name returns `TOOL_NOT_FOUND` and does not execute anything.
2. Invalid arguments return `INVALID_ARGUMENTS` and the tool is never executed.
3. A registered but not-enabled tool returns `UNAUTHORIZED`.
4. A write tool without approval returns `APPROVAL_REQUIRED` and **writes
   nothing** — assert the table row count is unchanged.
5. The same call with `approved: true` executes and writes exactly one row.
6. A tool throwing an exception returns `SERVICE_UNAVAILABLE`, not a 500.
7. A tool returning data that fails its output schema returns
   `UNEXPECTED_RESPONSE` and the invalid data is not passed on.
8. Every one of the above writes a log entry carrying the outcome code.

**`ToolCatalogueTest`**

1. `farm_profile_lookup` with a known id returns the expected fields.
2. An unknown id returns `RESOURCE_NOT_FOUND`.
3. `schedule_visit` with a past date returns `INVALID_ARGUMENTS`.
4. `schedule_visit` approved creates one `visits` row with a unique `visit_ref`.
5. `log_followup` against an unknown `visit_id` returns `RESOURCE_NOT_FOUND`.
6. Every registered tool's `inputSchema()` and `outputSchema()` are themselves
   well formed: `type` is `object`, `properties` is non-empty, and every name in
   `required` exists in `properties`.

Test 6 matters more than it looks. It is what stops a schema and its tool
drifting apart once Phase 2 generates model declarations from the same source.

## 6. Verification

```bash
php artisan migrate
php artisan test --filter=SchemaValidatorTest
php artisan test --filter=ToolInvokerTest
php artisan test --filter=ToolCatalogueTest
php artisan agrivisit:tools
php artisan agrivisit:tools --json
```

Then confirm in `php artisan tinker` that nothing executes without the gate:

```php
app(App\Services\Tools\ToolInvoker::class)->invoke('schedule_visit', ['farm_id' => 'UG-KYA-012', 'visit_date' => '2026-12-01', 'purpose' => 'Follow up leaf damage'])->code
// APPROVAL_REQUIRED
App\Models\Visit::count()
// 0
```

## 7. Out of scope

Function calling, model declarations, any change to `ChecklistDrafter`, the
officer-facing approval UI, the agent loop, and the weather tool — that one is
the Week 6 external integration, as the Week 1 charter states.

## 8. Report back

State the files created, the test results, what `agrivisit:tools` prints, and
anything in this brief that proved wrong or ambiguous. If a design rule in
section 2 could not be satisfied, say which and why rather than working around
it silently.
