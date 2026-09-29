<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AgriVisit — Visit Prep Console</title>
    <style>
        :root {
            --ink: #1a202c; --muted: #5a6773; --line: #dde3ea;
            --accent: #1f6f54; --accent-soft: #f3f8f5;
            --warn: #b7791f; --warn-soft: #fffaf0;
            --danger: #c53030; --danger-soft: #fff5f5;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 2rem 1rem; background: #f7f9fb; color: var(--ink);
            font: 16px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        .wrap { max-width: 860px; margin: 0 auto; }
        header { margin-bottom: 1.75rem; }
        h1 { margin: 0 0 .2rem; font-size: 1.6rem; color: var(--accent); }
        .sub { color: var(--muted); font-size: .92rem; }
        .card {
            background: #fff; border: 1px solid var(--line); border-radius: 10px;
            padding: 1.25rem; margin-bottom: 1.25rem;
        }
        label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: .35rem; }
        select, textarea {
            width: 100%; padding: .6rem .7rem; border: 1px solid var(--line);
            border-radius: 6px; font: inherit; font-size: .95rem; background: #fff;
        }
        textarea { min-height: 90px; resize: vertical; }
        .field { margin-bottom: 1rem; }
        button {
            background: var(--accent); color: #fff; border: 0; border-radius: 6px;
            padding: .65rem 1.3rem; font: inherit; font-weight: 600; cursor: pointer;
        }
        button:hover { background: #17573f; }
        .banner { border-radius: 8px; padding: .9rem 1.1rem; margin-bottom: 1.25rem; font-size: .93rem; }
        .banner.refused { background: var(--danger-soft); border: 1px solid var(--danger); }
        .banner.failed  { background: var(--warn-soft);   border: 1px solid var(--warn); }
        .banner.draft   { background: var(--accent-soft); border: 1px solid var(--accent); }
        .banner.no-evidence { background: #f7f9fb; border: 1px solid var(--muted); }
        .cite { margin-top: .35rem; font-size: .85rem; }
        .cite summary { cursor: pointer; color: var(--accent); }
        .cite summary code { background: #f0f3f6; padding: .05rem .3rem; border-radius: 3px; color: var(--ink); }
        .cite blockquote {
            margin: .45rem 0 0; padding: .6rem .8rem; background: #f7f9fb;
            border-left: 3px solid var(--accent); color: var(--ink); white-space: pre-wrap;
        }
        .banner strong { display: block; margin-bottom: .25rem; }
        ol.items { padding-left: 1.2rem; margin: 0; }
        ol.items li { margin-bottom: .95rem; }
        .item-head { font-weight: 600; }
        .cat {
            display: inline-block; font-size: .72rem; text-transform: uppercase;
            letter-spacing: .04em; color: var(--muted); border: 1px solid var(--line);
            border-radius: 20px; padding: .08rem .55rem; margin-left: .4rem; vertical-align: middle;
        }
        .why { color: var(--muted); font-size: .9rem; margin-top: .15rem; }
        .meta {
            font-size: .8rem; color: var(--muted); border-top: 1px solid var(--line);
            margin-top: 1.1rem; padding-top: .75rem;
        }
        .meta code { background: #f0f3f6; padding: .05rem .3rem; border-radius: 3px; }
        .errors { color: var(--danger); font-size: .88rem; margin-bottom: .8rem; }
    </style>
</head>
<body>
<div class="wrap">

    <header>
        <h1>AgriVisit</h1>
        <div class="sub">Visit Prep Console — Week 3. Drafts grounded in Ugandan extension manuals; no tools or memory yet.</div>
    </header>

    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('checklist.draft') }}" class="card">
        @csrf

        <div class="field">
            <label for="farm_id">Farm</label>
            <select name="farm_id" id="farm_id" required>
                <option value="">Select a farm…</option>
                @foreach ($farms as $farm)
                    <option value="{{ $farm['farm_id'] }}" @selected($farmId === $farm['farm_id'])>
                        {{ $farm['farm_id'] }} — {{ $farm['district'] }}
                        ({{ collect($farm['crops'])->pluck('crop')->join(', ') }})
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="notes">Officer notes <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
            <textarea name="notes" id="notes" placeholder="Anything the officer already knows about this visit.">{{ $notes }}</textarea>
        </div>

        <button type="submit">Draft checklist</button>
    </form>

    @if ($result)
        @if ($result->status === 'refused')
            <div class="banner refused">
                <strong>Request declined — {{ $result->refusalCategory }}</strong>
                {{ $result->message }}
            </div>
        @elseif ($result->status === 'failed')
            <div class="banner failed">
                <strong>No draft produced</strong>
                {{ $result->message }}
            </div>
        @elseif ($result->status === 'no_evidence')
            <div class="banner no-evidence">
                <strong>No supporting guidance found</strong>
                {{ $result->message }}
                <div class="meta" style="border:0;margin:.5rem 0 0;padding:0">Trace <code>{{ $result->traceId }}</code></div>
            </div>
        @else
            <div class="banner draft">
                <strong>Draft only — not approved for field use</strong>
                @if ($result->evidenceCount > 0)
                    Each item cites a passage from an extension manual. Open the citation to read the
                    passage and check it supports the item. The officer must still approve every item
                    before use.
                @else
                    Items are ungrounded: this prompt version does not use the extension manuals, so
                    nothing here carries a citation. The officer must verify every item before use.
                @endif
            </div>

            <div class="card">
                @if ($result->summary)
                    <p style="margin-top:0"><em>{{ $result->summary }}</em></p>
                @endif

                <ol class="items">
                    @foreach ($result->items as $item)
                        <li>
                            <span class="item-head">{{ $item['item'] }}</span>
                            <span class="cat">{{ $item['category'] }}</span>
                            <div class="why">{{ $item['rationale'] }}</div>
                            @if (! empty($item['citation']))
                                <details class="cite">
                                    <summary>
                                        Source: {{ $item['citation']['document'] }}@if ($item['citation']['section']) — {{ $item['citation']['section'] }}@endif
                                        <code>{{ $item['citation']['chunk_ref'] }}</code>
                                    </summary>
                                    <blockquote>{{ $item['citation']['text'] }}</blockquote>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ol>

                <div class="meta">
                    Prompt <code>{{ $result->promptVersion }}</code>
                    · Model <code>{{ $result->meta->model }}</code>
                    · Temperature <code>{{ $result->meta->temperature }}</code>
                    · {{ $result->meta->inputTokens }} in / {{ $result->meta->outputTokens }} out tokens
                    · {{ $result->meta->latencyMs }} ms
                    · {{ $result->evidenceCount }} evidence {{ \Illuminate\Support\Str::plural('passage', $result->evidenceCount) }} supplied
                    · Trace <code>{{ $result->traceId }}</code>
                </div>
            </div>
        @endif
    @endif

</div>
</body>
</html>
