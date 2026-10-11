# Fix — New Gemini Project and the Timeout Mismatch: Results

**Implemented from:** `prompts/FIX-quota-and-timeouts.md`
**Date:** 2026-10-06
**Status:** Code, config and tests complete. Step 3, the timeout proof, **passes live**. Steps 1–2 are **blocked by Google**: `gemini-3.6-flash` returned HTTP 503 "high demand" on all three tries.

| Part | State |
|---|---|
| New key picked up | Yes |
| `max_execution_time` | 30 → **120** (verified on a live built-in server) |
| `LLM_TIMEOUT` | 45 → **25** |
| 429 / timeout as handled failures | Done |
| Tests | **6 new**. Full suite **172 pass** (715 assertions) |
| Live step 3 (`LLM_TIMEOUT=1`) | Handled red panel, HTTP 200, logged as `timeout` |
| Live steps 1–2 | Not completed: Gemini 503 (Google capacity, not quota) |

Nothing has been committed. The API key was not printed, logged or written to any tracked file.

---

## 1. The root cause, restated

The clients already caught every transport error and every non-2xx response and turned them into `LlmException`. The fatal error was **not** an exception escaping that catch.

`max_execution_time` is a fatal error that PHP raises itself, and no `try/catch` can intercept it. The HTTP client was allowed 45 s while the process was allowed 30 s. A held, rate-limited request therefore outlived the process, and the handling code never got a chance to run.

The real fix is the ordering of the timeouts (section 3). The exception work (section 4) makes the failure readable once it is handled.

---

## 2. API key

| Check | Result |
|---|---|
| Variable the clients read | **`GOOGLE_API_KEY`**: `config('agrivisit.llm.google.key')`. The embedding client is given the same value in `AgriVisitServiceProvider` (no separate embedding key) |
| `.env` uses that exact name | Yes. Set (checked as yes/no only, value never displayed) |
| `php artisan config:clear` | Done. Runtime reads the key |
| `.env` in `.gitignore` | Yes (`.env` and `.env.*`). `git check-ignore` confirms. Not tracked |
| `.env.example` | Tracked. `GOOGLE_API_KEY=` is empty |
| `EMBEDDING_MODEL` / vectors | Untouched. No re-index |

---

## 3. Timeouts

**Rule:** the HTTP client must always time out before PHP does.

| Limit | Before | After | Where |
|---|---|---|---|
| PHP `max_execution_time` | 30 | **120** | `php.ini` reported by `php --ini` (WinGet PHP 8.4). Backup saved beside it as `php.ini.bak-before-timeout-fix` |
| `LLM_TIMEOUT` (Gemini drafting, Gemini function calling, Anthropic, OpenAI) | 45 | **25** | `.env`, `.env.example`, and the default in `config/agrivisit.php` |
| Embedding client | 30 | 30 (unchanged) | `config/agrivisit.php` → `embedding.timeout`, hard-coded |

The CLI always reports `max_execution_time = 0`. To confirm what `php artisan serve` actually gets, a throwaway built-in server was started and asked directly. It returned **120**.

### Every outbound call vs the 120 s limit

No single outbound call exceeds the limit. The brief asks about individual calls, but **the limit applies to a whole request**, and two request paths make calls in sequence:

| Request | Outbound calls | Worst case | Under 120 s? |
|---|---|---|---|
| Ask, proposing a call | 1 model call | 25 s | Yes |
| Ask, read tool, or approve after a write | 2 model calls | 50 s | Yes |
| Checklist draft (`POST /draft`) | 1 embedding + 1 model call | 30 + 25 = **55 s** | Yes |
| `agrivisit:*` artisan commands | many | — | CLI has no limit |

**Flag:** under the old 30 s limit, a draft could be killed after a 30 s embedding call even when every call was correctly bounded. Any future multi-call request (for example, the Week 5 agent loop) needs its total budget checked, not just each call's timeout.

### Action for you

Your `php artisan serve` on port 8000 was started under the old `php.ini` and **still has the 30 s limit**. Restart it.

---

## 4. Handled model failures

### `LlmException` (`app/Services/Llm/LlmException.php`)

Now carries `status` (the HTTP status, or null) and `kind`:

| Kind | Trigger | Officer sees |
|---|---|---|
| `rate_limited` | HTTP 429 | "The model is rate limited, try again later. (HTTP 429: the request or daily quota for this API key has been used up.)" |
| `timeout` | cURL error 28 / "timed out", HTTP 408 / 504 | "The model did not respond in time, try again later." |
| `unavailable` | HTTP 500 / 502 / 503, refused connection | "The model service is busy or unavailable, try again later. (HTTP 503)" |
| `http_error` | Other 4xx | "The model refused the request (HTTP n)." |
| `other` | Truncation, block, empty response | The existing message |

Two factories, `fromResponse()` and `fromTransport()`, are used by **both** Gemini clients:

- `GoogleClient` (drafting)
- `GeminiFunctionCallingClient` (tools)

The constructor is backward compatible, so existing `new LlmException($msg)` calls are unchanged.

### `ToolSession`

- `LlmException` → `model_error` result. The officer sees `reason()`.
- The session log keeps `model_error: {kind, status, message}`, including the raw API body.
- The approved-write path does the same and still carries the tool result, so the approval record stays complete.

### Tool Console

The red panel heading is now "Model failure — the model could not answer", followed by the reason. No stack trace, and the raw API body is not shown.

### Not changed

`EmbeddingException` already carried the status and has `isRateLimited()`. `ChecklistDrafter` was left alone: it already catches both exception types.

---

## 5. Tests added

**`tests/Feature/ModelFailureTest.php`** — fake model client, through the controller:

1. A 429 → HTTP 200, red panel, "The model is rate limited, try again later.", raw body not shown. Exactly one session-log entry with `status: model_error`, `model_error.status: 429`, `kind: rate_limited`. No visit written. **This is the brief's required test.**
2. A timeout → handled panel; session log `kind: timeout`.
3. A 429 *after* an approved write → handled. The visit exists, the gate log records `OK` / `approved`, and the session log records the `model_error`.

**`tests/Feature/GeminiFunctionCallingClientTest.php`** — real client, faked HTTP:

4. HTTP 429 → `LlmException` with `status 429`, `kind rate_limited`.
5. Connection failure "cURL error 28" → `kind timeout`, message names the 5 s limit.
6. Refused connection → `unavailable`. HTTP 503 → `unavailable`, `status 503`.

---

## 6. Live verification

Run on two throwaway built-in servers using the new `php.ini`:

- port 8001: normal config
- port 8002: `LLM_TIMEOUT=1` set as a **process environment variable**, so `.env` was never edited and did not need restoring

Your port-8000 server was not touched. Both test servers were stopped afterwards.

| Step | Result |
|---|---|
| 1. Ask to book a visit | **Blocked by Google.** Three tries, each HTTP 503 `UNAVAILABLE`: "This model is currently experiencing high demand…". Each came back in 2–3 s as a **handled** red panel ("busy or unavailable … (HTTP 503)"), HTTP 200, no error page. This is a real-world proof of the handling, but not of a completed booking |
| 2. Session-log entry with token counts | An entry is written for every attempt (`status: model_error`, `kind: unavailable`, `status: 503`). Token counts are 0 because the model never answered. **Re-run when Gemini recovers** to see non-zero counts |
| 3. `LLM_TIMEOUT=1` | **Pass.** HTTP 200 in 2 s. Red panel: "The model did not respond in time, try again later." Session log: `kind: timeout`, "timed out after 1 s: cURL error 28: Operation timed out after 1003 milliseconds with 0 bytes received". The logged URL has no key, because the key goes in a header |

No approval was submitted, so nothing was written: `tool_approvals` is still 0 rows and `visits` is still 4.

### Where the brief does not match the system

1. **"The tool log and session log must both record it" (step 3).** The tool log is the gate's per-call log. When the *first* model call times out, no tool is called, so there is nothing true for the gate to record. Only the session log has an entry. A tool-log entry appears only when the failure comes after a tool ran (approve path); test 3 covers that case. Writing a gate entry for a call that never happened would falsify the evidence table.
2. **Root cause.** The brief's section 4 implies a 429 escaped the exception handling. It did not: the failure was PHP's uncatchable time-limit fatal (see §1). Section 4 still improves what the officer sees and what gets logged.
3. **Quota vs capacity.** Today's failures were 503 (Google capacity), not 429 (quota). The new project's quota looks fine. Rate-limit handling is proven by tests 1, 3 and 4.

---

## Files

| File | Change |
|---|---|
| `app/Services/Llm/LlmException.php` | `status`, `kind`, `fromResponse()`, `fromTransport()`, `reason()` |
| `app/Services/Llm/GoogleClient.php` | Uses the factories |
| `app/Services/Llm/FunctionCalling/GeminiFunctionCallingClient.php` | Uses the factories |
| `app/Services/Tools/ToolSession.php` | `reason()` to the officer; `model_error` detail to the session log |
| `resources/views/tools/index.blade.php` | Model-failure panel heading |
| `config/agrivisit.php` | `LLM_TIMEOUT` default 45 → 25 |
| `.env.example` | `LLM_TIMEOUT=25` |
| `.env` *(untracked)* | `LLM_TIMEOUT=25`; nothing else touched |
| `php.ini` *(outside repo)* | `max_execution_time = 120` (backup alongside) |
| `tests/Feature/ModelFailureTest.php` | New, 3 tests |
| `tests/Feature/GeminiFunctionCallingClientTest.php` | 3 tests added |

## To finish

1. Restart `php artisan serve`.
2. When Gemini stops returning 503, run step 1 (ask to book a visit), then check the newest line of `storage/app/tool-sessions/<today>.jsonl` for non-zero `totals`.
