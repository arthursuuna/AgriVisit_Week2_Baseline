# Week 4 Phase 4 — Failure and Authorization Evidence: Results

**Implemented from:** `prompts/IMPLEMENT-W4-PHASE-4.md`
**Date:** 2026-10-07
**Status:** Code, command and tests complete. **All 10 person-caused cases pass against the live database.** Of the model-caused rows, M4 is recorded (from 2026-10-06); M1, M2, M3, M5 and the screenshots are still to do by hand.

| Part | State |
|---|---|
| Probe tools (section 2) | Done. Off by default; never declared to the model |
| `php artisan agrivisit:evidence` (section 3) | 10 / 10 pass, exit 0, nothing written |
| `FailureEvidenceTest` (section 7) | **7 pass** (the brief's 6, with test 3 split into a throwing run and a normal run) |
| Full suite | **186 pass** (816 assertions) |
| Evidence file | `evidence/week4-failure-evidence.md` (+ `.json`) |
| Model-caused rows (section 4) | M4 done; M1, M2, M3, M5 pending (quota) |
| Screenshots (section 5) | Pending; `evidence/screenshots/week4/` created |

Nothing has been committed.

## Files

### Added

| File | Purpose |
|---|---|
| `app/Services/Tools/DiagnosticTool.php` | Marker interface for test instrumentation |
| `app/Services/Tools/Catalogue/FaultyProbeTool.php` | Throws → `SERVICE_UNAVAILABLE` |
| `app/Services/Tools/Catalogue/MalformedProbeTool.php` | Breaks its own output schema → `UNEXPECTED_RESPONSE`, data discarded |
| `app/Console/Commands/GatherFailureEvidence.php` | `agrivisit:evidence`: 10 cases through the real `ToolInvoker`, with `--output` |
| `tests/Feature/FailureEvidenceTest.php` | 7 tests |
| `evidence/week4-failure-evidence.md` / `.json` | The deliverable, plus machine-readable results |

### Changed

| File | Change |
|---|---|
| `config/agrivisit.php` | `tools.probes_enabled` (`TOOLS_PROBES_ENABLED`, default false). `TOOLS_DISABLED` (comma list) subtracts from the enabled list, so M3 needs no code edit |
| `app/Providers/AgriVisitServiceProvider.php` | Registers the probes only when `probes_enabled` is true |
| `app/Services/Tools/ToolPolicy.php` | A `DiagnosticTool` is allowed only while `probes_enabled` is true |
| `app/Services/Tools/ToolSession.php` | `declarations()` excludes every `DiagnosticTool` |

## Command output (live database, `TOOLS_PROBES_ENABLED=true`)

| # | Condition | Expected | Returned | DB unchanged | call_id | Result |
|---|---|---|---|---|---|---|
| 1 | Unlisted tool call | TOOL_NOT_FOUND | TOOL_NOT_FOUND | yes | b195b9c5 | PASS |
| 2 | Invalid parameter (format) | INVALID_ARGUMENTS | INVALID_ARGUMENTS | yes | e4f8678e | PASS |
| 3 | Missing parameter | INVALID_ARGUMENTS | INVALID_ARGUMENTS | yes | ea4afb5a | PASS |
| 4 | Unexpected parameter | INVALID_ARGUMENTS | INVALID_ARGUMENTS | yes | 90402598 | PASS |
| 5 | Business rule, approval supplied | INVALID_ARGUMENTS | INVALID_ARGUMENTS | yes | bc1d113d | PASS |
| 6 | Subject does not exist | RESOURCE_NOT_FOUND | RESOURCE_NOT_FOUND | yes | 790d5353 | PASS |
| 7 | Higher-impact action without approval | APPROVAL_REQUIRED | APPROVAL_REQUIRED | yes | 757779ba | PASS |
| 8 | Unauthorized (tool disabled) | UNAUTHORIZED | UNAUTHORIZED | yes | 70dd6ff5 | PASS |
| 9 | Unavailable service | SERVICE_UNAVAILABLE | SERVICE_UNAVAILABLE | yes | 2d76751e | PASS |
| 10 | Unexpected tool response | UNEXPECTED_RESPONSE | UNEXPECTED_RESPONSE | yes | 9b08b34e | PASS |

These are the first run's call ids; the evidence file holds those of its latest run. Counts (visits / followups / tool_approvals) stayed at 7/2/4 throughout. Run without the flag, `agrivisit:tools` lists only the three product tools.

## Tests

1. The command runs end to end and writes the file. A re-run replaces only the automated block, and hand-written text survives.
2. Every case returns its expected code and has a tool-log `call_id`. Case 10 returns no data.
3. Case 8 restores the enabled list when the invocation **throws** (mocked invoker). The command exits 1 and records `EXCEPTION`.
3b. Case 8 restores the enabled list after a normal run.
4. With an existing visit and followup present, no case changes any count.
5. With probes disabled: they are not registered, not in the picker, and `TOOL_NOT_FOUND` if called. The command marks cases 9 and 10 **skipped** and exits 1, rather than reporting a false pass.
6. With probes enabled, the picker lists them, but the model is declared exactly `farm_profile_lookup`, `schedule_visit`, `log_followup`.

## Model-caused cases

| Row | State |
|---|---|
| M4: unavailable model (`LLM_TIMEOUT=1`) | **Done** 2026-10-06. Handled panel, session `48398a39…`, `kind: timeout` |
| M1 `APPROVAL_REQUIRED`, M2 `INVALID_ARGUMENTS`, M5 `TOOL_NOT_FOUND` | Pending: need quota and Gemini without 503s |
| M3 `UNAUTHORIZED` | Pending. Expected to **decline** (see finding 1) |

I did not run the live model asks, so I make no claim about what the model does for `TOOL_NOT_FOUND`.

## Where the brief was wrong or ambiguous

1. **Model-caused `UNAUTHORIZED` conflicts with Phase 2.** Section 4 expects "proposed, refused by policy". But `ToolSession` does not declare a disabled tool to the model (a Phase 2 test), so the model is never offered `log_followup`. It will most likely decline. `UNAUTHORIZED` would appear only if it called a tool it was not offered. Not declaring the tool is the stronger control, so it was kept, and the evidence file records whichever outcome happens.
2. **Model-caused `SERVICE_UNAVAILABLE` is a different failure.** `LLM_TIMEOUT=1` makes the *model* unavailable: a handled `model_error` / `timeout`. The gate's `SERVICE_UNAVAILABLE` code is about a *tool's* dependency, and only the probe can cause that, and the probe is never offered to the model. The summary says "model-side" rather than claiming the same code.
3. **Probes need policy permission as well as registration.** If they were registered but not in the enabled list, they would return `UNAUTHORIZED`, not the failures they exist to show. A `DiagnosticTool` marker gives one switch that covers registration, policy and exclusion from the model.
4. **The command "writes" a file that also holds hand-recorded rows.** Overwriting would destroy them, so only the block between `BEGIN` / `END AUTOMATED` markers is rewritten.
5. **"Exits non-zero if any case returns another code."** It also exits non-zero when a case changes a count, throws, or is skipped because probes are off. A skipped case is not a pass.
6. **Screenshot 5 (a real 503 after an approved write)** depends on Google's capacity at the moment of approval and cannot be forced. The path is covered by `ModelFailureTest`.
7. **`TOOLS_PROBES_ENABLED=true php artisan …`** is bash syntax. In PowerShell, use `$env:TOOLS_PROBES_ENABLED='true'; php artisan agrivisit:evidence; Remove-Item Env:TOOLS_PROBES_ENABLED`. A real environment variable overrides `.env`, so `.env` never needs the flag.

## To finish by hand

1. `php artisan serve`, then the Ask panel: M1, M2, M5. Then M3 with `TOOLS_DISABLED=log_followup` in `.env` and `php artisan config:clear` (remove both afterwards).
2. Take the six screenshots listed in the evidence file.
3. Fill in the _pending_ cells in `evidence/week4-failure-evidence.md`.
4. Confirm `TOOLS_PROBES_ENABLED` is not set in `.env`, and that the picker shows no probes.
