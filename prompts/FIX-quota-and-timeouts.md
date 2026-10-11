# Fix — New Gemini Project and the Timeout Mismatch

Two unrelated problems surfaced while running the Phase 3 browser checks. Fix
both before continuing.

## 1. What happened

A Phase 3 Ask request died with
`Symfony\Component\ErrorHandler\Error\FatalError — Maximum execution time of 30
seconds exceeded`, thrown from Guzzle's cURL handler.

The cause was not the code. The Gemini project had exceeded its free-tier daily
cap — 22 requests against a limit of 20 on `gemini-3.6-flash` — so the request
was held rather than answered, and PHP's execution limit killed the process
before the HTTP client gave up.

Two separate defects are visible in that one failure:

- the account is out of quota, and
- a rate-limited call arrives as an unhandled fatal error instead of a handled
  `SERVICE_UNAVAILABLE` through the tool layer.

The second is the more important one. The tool layer has careful failure
handling that never got the chance to run.

## 2. The API key has been moved to a new project

A new Google Cloud project has been created with its own quota, and its key has
already been placed in `.env` by hand. **Do not write the key into any file
under version control**, and do not echo it into a report, a log or a commit.

Confirm the application picks it up:

1. Check which environment variable the Gemini clients read — look in
   `config/agrivisit.php` for the embedding and LLM blocks — and confirm the key
   in `.env` uses exactly that name.
2. `php artisan config:clear`
3. Confirm `.env` is still listed in `.gitignore`, and that `.env.example`
   contains the variable name with an empty value and no real key.

Leave `EMBEDDING_MODEL` and the embedding vectors alone. The 301 corpus vectors
were built with `gemini-embedding-001` and remain valid; re-indexing is neither
needed nor wanted.

## 3. Fix the timeout mismatch

`LLM_TIMEOUT` is 45 seconds while PHP's `max_execution_time` is 30. The HTTP
client is therefore willing to wait longer than the process is allowed to live,
so a slow or throttled call becomes a fatal error rather than a handled failure.

**The rule to apply: the HTTP client must always time out before PHP does.**

1. Set `max_execution_time = 120` in the `php.ini` that `php --ini` reports, and
   restart `php artisan serve`.
2. Set `LLM_TIMEOUT=25` in `.env`.
3. Check every other outbound HTTP timeout in the application — the embedding
   client has its own — and confirm each is comfortably below the PHP limit.
   Report any that are not.

## 4. Make a rate-limited call a handled failure

A 429 from Gemini must surface as a normal failure that the tool layer can
report, not as an exception that escapes to an error page.

1. In the Gemini clients, catch a connection timeout and a 429 and convert each
   into the existing `LlmException`, carrying the HTTP status.
2. In `ToolSession`, make sure a model failure returns a `model_error` result
   and is written to the session log, rather than propagating.
3. In the Tool Console, render a model failure as a plain red panel with the
   reason — "the model is rate limited, try again later" — rather than a stack
   trace.

Add a test, using the fake client, asserting that a 429 from the model produces
a handled result and a session-log entry, and that no exception escapes the
controller.

## 5. Verification

```bash
php artisan config:clear
php artisan test
php artisan serve
```

Then:

1. Ask the assistant to book a visit. It should complete rather than time out.
2. Confirm a session-log entry was written with its token counts.
3. Temporarily set `LLM_TIMEOUT=1` in `.env`, clear config, and ask again. The
   console must show a handled model-failure panel, not an error page. The tool
   log and session log must both record it. Then set the timeout back to 25.

Step 3 is the one that proves the fix. A timeout that cannot be demonstrated
safely is not a timeout that has been handled.

## 6. Report back

State which environment variable the clients read, the timeouts found and what
they were set to, the test added, and what step 3 produced. Flag any other
outbound call whose timeout exceeds the PHP execution limit.
