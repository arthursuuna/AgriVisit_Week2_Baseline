# Implement Week 4 Phase 2 — Function Calling

Let the model propose tool calls. The gate built in Phase 1 decides whether they
run.

## 1. Context

Phase 1 built three tools behind `ToolInvoker`, which checks the allow-list,
validates arguments, enforces approval for writes and validates output. All of
it works and is tested. The model knows none of it exists.

This phase declares the tools to the model, handles a proposed call, routes it
through the same gate, and feeds the result back so the model can answer in
plain language.

**This is single-shot, not a loop.** One request, at most one tool call, one
final answer. Re-planning and multi-step execution are Week 5. If you find
yourself writing a `while` loop around the model, stop.

### Do not modify

`app/Services/Tools/` (the whole layer, including the three tools),
`ChecklistDrafter`, `ChecklistParser`, `app/Services/Retrieval/`,
`app/Services/Corpus/`, `resources/prompts/checklist/`.

Existing classes under `app/Services/Llm/` must not be changed either — add new
ones alongside them.

## 2. Design rules

1. **One schema, two uses.** The model's function declarations are generated
   from each tool's `inputSchema()`. No second copy, no hand-written
   declarations.
2. **The model proposes, the gate disposes.** A proposed call goes through
   `ToolInvoker::invoke()` exactly as a human-initiated one does. No shortcut,
   no separate path, no trusting the model's arguments.
3. **The model can never approve.** `$approved` is supplied by a human action
   only. There is no field, prompt or tool by which the model can set it.
4. **A hallucinated tool is an expected case, not an error.** Models sometimes
   call a function that was never declared. It returns `TOOL_NOT_FOUND` through
   the normal path and is reported back to the model as a failed result.
5. **At most one tool call per request in this phase.** If the model proposes
   several, execute none and return a clear message. Parallel and sequential
   calls arrive in Week 5.
6. **The restricted-topic guard runs first**, on the natural-language request,
   before any model call — as it does everywhere else in the system.

## 3. Translating schemas into function declarations

Gemini's `functionDeclarations` accept an OpenAPI subset: `type`, `description`,
`properties`, `required`, `enum`, `items`. Types are `STRING`, `NUMBER`,
`INTEGER`, `BOOLEAN`, `ARRAY`, `OBJECT`.

They do **not** accept `pattern`, `minLength`, `maxLength`, `minimum`,
`maximum`, `format`, or union types such as `["string","null"]`. The Phase 1
report flagged this.

Build `app/Services/Llm/FunctionCalling/SchemaTranslator.php`:

```php
public function toFunctionDeclaration(ToolContract $tool): array;
```

**Do not simply strip the unsupported keywords.** Fold each into the property's
`description`, so the model still knows the rule even though the API cannot
express it:

| Dropped keyword | Appended to the description |
|---|---|
| `pattern: ^UG-[A-Z]{3}-\d{3}$` | "Format: UG-XXX-000, for example UG-KYA-012." |
| `format: date` | "Format: YYYY-MM-DD. Must be today or a future date." |
| `maxLength: 200` | "Maximum 200 characters." |
| `minimum` / `maximum` | "Between X and Y." |

A model told the rule produces valid arguments most of the time; a model not
told produces `INVALID_ARGUMENTS` most of the time, and your evidence table
fills with avoidable failures.

Write a test asserting that the translated declaration contains no unsupported
keyword anywhere in its structure, at any depth.

## 4. The client

`app/Services/Llm/FunctionCalling/GeminiFunctionCallingClient.php`. A new class;
do not alter `GoogleClient`.

Request shape:

```
POST {endpoint}/{model}:generateContent
{
  "system_instruction": { "parts": [ { "text": "..." } ] },
  "contents": [ ... conversation turns ... ],
  "tools": [ { "function_declarations": [ ... ] } ],
  "tool_config": { "function_calling_config": { "mode": "AUTO" } },
  "generationConfig": { "temperature": 0.2, "maxOutputTokens": 4000 }
}
```

Key in the `x-goog-api-key` header. Keep the thinking budget settings already
used elsewhere — the Week 2 truncation finding applies here too.

A response part is either `text` or `functionCall { name, args }`. Return a
value object exposing which it is, the text, and the proposed calls as a list
(so rule 5 can detect more than one).

Sending a tool result back means appending two turns to `contents`: the model's
own turn carrying the `functionCall`, then a `user`-role turn carrying a
`functionResponse` part with the tool name and the result. The whole
conversation is resent — the API is stateless.

## 5. The orchestrator

`app/Services/Tools/ToolSession.php`. This is the application layer the brief
asks for, and it is where the sequence lives.

```php
public function handle(string $request, ?array $approvedCall = null): SessionResult;
```

With no `$approvedCall`:

1. Screen `$request` with `RestrictedTopicGuard`. Refused → return immediately,
   no model call.
2. Build declarations for every enabled tool via the translator.
3. Call the model with the request and the declarations.
4. If the response is plain text, return it. No tool was needed, which is a
   valid outcome.
5. If it proposes more than one call, return a message saying only one call per
   request is supported in this phase. Execute nothing.
6. If it proposes one call, invoke it through `ToolInvoker` **without**
   approval.
   - A read tool executes. Go to step 7.
   - A write tool returns `APPROVAL_REQUIRED`. Return a result carrying the
     pending call and the tool's `approvalSummary()`. Stop here — the model is
     not called again.
   - Any failure code is also a result: send it back to the model (step 7) so it
     can explain the failure to the user rather than leaving them with a raw
     code.
7. Append the model turn and the `functionResponse` turn, call the model again,
   and return its final text alongside the tool result.

With `$approvedCall` supplied, skip to step 6 and invoke with `approved: true`,
then continue to step 7.

`SessionResult` carries: the final text, the tool name called (if any), the
`ToolResult`, the pending call (if awaiting approval), token counts, and a
session id.

### System instruction

Write it at `resources/prompts/tools/v1.0/system.md`, in the same six-part shape
as the checklist prompts. It must state: the assistant helps a Ugandan extension
officer; it may call the supplied tools; it must not invent farm data it has not
looked up; writing actions require officer approval and it should say so rather
than claiming an action is done; and it must never give pesticide doses or
clinical advice.

## 6. Tracing

Extend the tool log, or write a session log beside it, recording per request:
the request text, declarations offered (names only), the proposed call and its
arguments, the gate outcome, whether approval was required, the approved call if
any, and both model turns' token counts.

This is where Phase 4's "unauthorized requests" and "unexpected tool responses"
evidence comes from when the *model* is the caller rather than a person.

## 7. UI — extend the Tool Console

Add an **Ask** panel above the existing manual tool picker:

- a text box and a Send button,
- the model's reply,
- when a tool ran: which tool, its arguments, and the outcome code,
- when approval is needed: the same amber panel as Phase 1, with Approve and
  Cancel, posting the pending call back to `ToolSession::handle()`.

Keep the manual picker. It is how a tool is tested without spending quota.

## 8. Tests

Most of these must run with **no API key and no network**, by injecting a fake
client that returns canned responses. Quota is limited; a test suite that needs
it is a test suite that stops working.

`tests/Feature/SchemaTranslatorTest.php`

1. Every one of the three tools translates without error.
2. No unsupported keyword appears anywhere in a translated declaration.
3. A dropped `pattern` appears in the property description.
4. A dropped `maxLength` appears in the property description.
5. `enum` survives translation unchanged.
6. The declaration's `required` list matches the tool's schema.

`tests/Feature/ToolSessionTest.php`, with a fake client

1. Plain-text response with no call → returned as is, no tool invoked.
2. A proposed `farm_profile_lookup` executes and its data reaches the second
   model turn.
3. A proposed `schedule_visit` returns `APPROVAL_REQUIRED`, writes nothing, and
   **the model is called only once** — assert the fake client's call count.
4. The same call resubmitted as an approved call writes exactly one row.
5. A proposed call to `delete_farm` returns `TOOL_NOT_FOUND` and that failure is
   reported back to the model.
6. A proposed call with a malformed `farm_id` returns `INVALID_ARGUMENTS` and
   the tool is never executed.
7. Two proposed calls in one response execute nothing.
8. A restricted request ("what dose of pesticide should I use") is refused
   before any model call — assert the fake client was never called.
9. The model cannot cause an approved execution: a response attempting to set
   any approval-like field still yields `APPROVAL_REQUIRED`.

Test 9 is the one that proves design rule 3.

## 9. Verification

```bash
php artisan test --filter=SchemaTranslatorTest
php artisan test --filter=ToolSessionTest
php artisan serve
```

Then, spending a small amount of quota, try these four in the Ask panel:

| Ask | Expect |
|---|---|
| "What crops are grown on UG-KYA-012 and what issues are outstanding?" | Calls `farm_profile_lookup`, answers from its data |
| "Book a visit to UG-KYA-012 on 20 November to follow up the leaf damage" | Proposes `schedule_visit`, approval panel appears, nothing written until approved |
| "What is the weather going to be like next week?" | No tool fits. The model should say it cannot do that, not invent a forecast |
| "What dose of pesticide should the farmer apply?" | Refused by the guard before any model call |

Record the token counts — they feed the Week 4 report.

## 10. Out of scope

An agent loop, multiple or chained tool calls, the weather tool, changes to the
checklist drafting flow, and persistent memory.

## 11. Report back

State the files added, test results, what each of the four verification asks
produced, token counts, and anything in this brief that proved wrong or
ambiguous — particularly anything about the Gemini request shape.
