<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AgriVisit — Tool Console</title>
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
        h2 { margin: 0 0 .75rem; font-size: 1.05rem; }
        .sub { color: var(--muted); font-size: .92rem; }
        .sub a, .nav a { color: var(--accent); }
        .nav { float: right; font-size: .9rem; margin-top: .4rem; }
        .card {
            background: #fff; border: 1px solid var(--line); border-radius: 10px;
            padding: 1.25rem; margin-bottom: 1.25rem;
        }
        label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: .35rem; }
        label.inline { display: inline; font-weight: 400; }
        select, textarea, input[type=text], input[type=date], input[type=number] {
            width: 100%; padding: .6rem .7rem; border: 1px solid var(--line);
            border-radius: 6px; font: inherit; font-size: .95rem; background: #fff;
        }
        textarea { min-height: 90px; resize: vertical; }
        .field { margin-bottom: 1rem; }
        .req { color: var(--danger); font-weight: 400; }
        .opt { color: var(--muted); font-weight: 400; }
        .help { color: var(--muted); font-size: .82rem; margin-top: .25rem; }
        .help code, .meta code, td code, .banner code {
            background: #f0f3f6; padding: .05rem .3rem; border-radius: 3px; color: var(--ink);
        }
        .row { display: flex; gap: .75rem; align-items: flex-end; flex-wrap: wrap; }
        .row > .grow { flex: 1 1 220px; }
        button, .btn {
            background: var(--accent); color: #fff; border: 0; border-radius: 6px;
            padding: .65rem 1.3rem; font: inherit; font-weight: 600; cursor: pointer;
            text-decoration: none; display: inline-block;
        }
        button:hover, .btn:hover { background: #17573f; }
        button.secondary { background: #fff; color: var(--ink); border: 1px solid var(--line); }
        button.secondary:hover { background: #f7f9fb; }
        .refusals { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; font-size: .88rem; }
        .refusals a {
            border: 1px solid var(--danger); color: var(--danger); background: var(--danger-soft);
            border-radius: 20px; padding: .2rem .75rem; text-decoration: none;
        }
        .refusals a:hover { background: #fed7d7; }
        .banner { border-radius: 8px; padding: .9rem 1.1rem; margin-bottom: 1.25rem; font-size: .93rem; }
        .banner.ok      { background: var(--accent-soft); border: 1px solid var(--accent); }
        .banner.pending { background: var(--warn-soft);   border: 1px solid var(--warn); }
        .banner.refused { background: var(--danger-soft); border: 1px solid var(--danger); }
        .banner.notice  { background: #f7f9fb; border: 1px solid var(--muted); }
        .banner strong { display: block; margin-bottom: .25rem; }
        .code { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 1rem; }
        pre {
            margin: .6rem 0 0; padding: .75rem .9rem; background: #fff; border: 1px solid var(--line);
            border-radius: 6px; overflow-x: auto; font-size: .85rem;
        }
        ul.errs { margin: .4rem 0 0; padding-left: 1.2rem; }
        table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        th, td { text-align: left; padding: .4rem .5rem; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; }
        .tag { display: inline-block; font-size: .78rem; font-weight: 600; border-radius: 20px; padding: .05rem .55rem; }
        .tag.ok      { color: var(--accent); background: var(--accent-soft); border: 1px solid var(--accent); }
        .tag.pending { color: var(--warn);   background: var(--warn-soft);   border: 1px solid var(--warn); }
        .tag.refused { color: var(--danger); background: var(--danger-soft); border: 1px solid var(--danger); }
        .actions { display: flex; gap: .6rem; margin-top: .9rem; }
        .meta {
            font-size: .8rem; color: var(--muted); border-top: 1px solid var(--line);
            margin-top: 1.1rem; padding-top: .75rem;
        }
        .muted { color: var(--muted); }
    </style>
</head>
<body>
<div class="wrap">

    <header>
        <span class="nav"><a href="{{ route('checklist.index') }}">← Visit Prep Console</a></span>
        <h1>AgriVisit</h1>
        <div class="sub">Tool Console — Week 4. Ask the model, or call a tool by hand below; either way every call
            goes through the same gate, and write tools are held for approval.</div>
    </header>

    {{-- Ask: the model proposes a call, the same gate decides --}}
    <form method="POST" action="{{ route('tools.ask') }}" class="card">
        @csrf
        <h2>Ask <span class="muted" style="font-weight:400;font-size:.85rem">— the model may call one tool; uses API quota</span></h2>
        <div class="field">
            <textarea name="question" id="question" style="min-height:70px"
                placeholder="e.g. What crops are grown on UG-KYA-012 and what issues are outstanding?">{{ $ask['question'] ?? '' }}</textarea>
        </div>
        <button type="submit">Send</button>
    </form>

    @if (! empty($ask['error']))
        <div class="banner refused">{{ $ask['error'] }}</div>
    @endif

    @if (! empty($ask['notice']))
        <div class="banner notice">{{ $ask['notice'] }}</div>
    @endif

    @if (! empty($ask['result']))
        @php($session = $ask['result'])
        @php($toolCode = $session->toolResult ? ($session->toolResult->code ?? 'OK') : null)

        @if ($session->status === \App\Services\Tools\SessionResult::AWAITING_APPROVAL)
            <div class="banner pending">
                <strong><span class="code">APPROVAL_REQUIRED</span></strong>
                <p style="margin:.2rem 0 .7rem;font-size:1rem">{{ $session->pending['summary'] }}</p>

                <table>
                    <tr><th>Tool</th><td><code>{{ $session->pending['tool'] }}</code> <span class="muted">proposed by the model</span></td></tr>
                    @foreach ($session->pending['arguments'] as $key => $val)
                        <tr><th>{{ $key }}</th><td>{{ is_scalar($val) ? $val : json_encode($val) }}</td></tr>
                    @endforeach
                </table>

                <form method="POST" action="{{ route('tools.ask.approve') }}" class="actions">
                    @csrf
                    <input type="hidden" name="token" value="{{ $ask['token'] }}">
                    <button type="submit" name="decision" value="approve">Approve</button>
                    <button type="submit" name="decision" value="cancel" class="secondary">Cancel</button>
                </form>
            </div>
        @else
            <div class="banner {{ in_array($session->status, ['refused', 'too_many_calls', 'model_error'], true) ? 'refused' : 'ok' }}">
                <strong>
                    @if ($session->status === 'refused')
                        Refused before any model call — {{ $session->refusalCategory }}
                    @elseif ($session->status === 'too_many_calls')
                        More than one tool call proposed — nothing was run
                    @elseif ($session->status === 'model_error')
                        Model error
                    @else
                        Reply{{ ! empty($ask['approved']) ? ' (after officer approval)' : '' }}
                    @endif
                </strong>
                <div style="white-space:pre-wrap">{{ $session->text }}</div>

                @if ($session->toolName)
                    <table style="margin-top:.8rem">
                        <tr><th>Tool called</th><td><code>{{ $session->toolName }}</code></td></tr>
                        @foreach ($session->toolArguments ?? [] as $key => $val)
                            <tr><th>{{ $key }}</th><td>{{ is_scalar($val) ? var_export($val, true) : json_encode($val) }}</td></tr>
                        @endforeach
                        <tr><th>Outcome</th><td>
                            <span class="tag {{ $toolCode === 'OK' ? 'ok' : ($toolCode === 'APPROVAL_REQUIRED' ? 'pending' : 'refused') }}">{{ $toolCode }}</span>
                            @if ($toolCode !== 'OK') <span class="muted">{{ $session->toolResult->message }}</span>@endif
                        </td></tr>
                    </table>
                    @if ($toolCode === 'OK')
                        <details style="margin-top:.5rem;font-size:.85rem"><summary>Tool data</summary>
                            <pre>{{ json_encode($session->toolResult->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    @endif
                @endif
            </div>
        @endif

        @if ($session->modelCalls() > 0)
            @php($totals = $session->totalTokens())
            <div class="meta" style="margin:-.6rem 0 1.25rem;border:0">
                {{ $session->modelCalls() }} model {{ \Illuminate\Support\Str::plural('call', $session->modelCalls()) }}
                @foreach ($session->tokens as $t)
                    · turn {{ $t['turn'] }}: {{ $t['input_tokens'] }} in / {{ $t['output_tokens'] }} out / {{ $t['thinking_tokens'] }} thinking
                @endforeach
                · total {{ $totals['input_tokens'] }} in / {{ $totals['output_tokens'] }} out / {{ $totals['thinking_tokens'] }} thinking
                · Session <code>{{ \Illuminate\Support\Str::limit($session->sessionId, 8, '') }}</code>
            </div>
        @endif
    @endif

    {{-- Tool picker --}}
    <form method="GET" action="{{ route('tools.index') }}" class="card">
        <div class="row">
            <div class="grow">
                <label for="tool">Tool</label>
                <select name="tool" id="tool" onchange="this.form.submit()">
                    @foreach ($tools as $t)
                        <option value="{{ $t->name() }}" @selected($selected === $t->name())>
                            {{ $t->name() }} — {{ $t->isWrite() ? 'write, needs approval' : 'read' }}
                        </option>
                    @endforeach
                    <option value="{{ \App\Http\Controllers\ToolConsoleController::OTHER }}" @selected($selected === \App\Http\Controllers\ToolConsoleController::OTHER)>
                        other (type a name)…
                    </option>
                </select>
            </div>
            <noscript><button type="submit" class="secondary">Switch</button></noscript>
        </div>

        <div class="refusals" style="margin-top:.9rem">
            <span class="muted">Try a refusal:</span>
            @foreach ($refusals as $r)
                <a href="{{ route('tools.index', $r['query']) }}" title="Expected: {{ $r['expect'] }}">{{ $r['label'] }}</a>
            @endforeach
        </div>
    </form>

    {{-- Generated form --}}
    <form method="POST" action="{{ route('tools.run') }}" class="card">
        @csrf
        <input type="hidden" name="tool" value="{{ $selected }}">

        @if ($tool)
            <h2>{{ $tool->name() }}</h2>
            <p class="help" style="margin-top:-.5rem">{{ $tool->description() }}</p>

            @foreach ($fields as $f)
                @php($value = $values[$f['name']] ?? '')
                <div class="field">
                    @if ($f['control'] === 'checkbox')
                        <input type="checkbox" name="args[{{ $f['name'] }}]" id="f_{{ $f['name'] }}" value="1" @checked($value)>
                        <label class="inline" for="f_{{ $f['name'] }}"><strong>{{ $f['name'] }}</strong></label>
                    @else
                        <label for="f_{{ $f['name'] }}">
                            {{ $f['name'] }}
                            @if ($f['required'])<span class="req">*</span>@else<span class="opt">(optional)</span>@endif
                        </label>
                    @endif

                    {{-- No required/pattern/maxlength/min attributes: invalid input must reach the invoker. --}}
                    @switch($f['control'])
                        @case('select')
                            <select name="args[{{ $f['name'] }}]" id="f_{{ $f['name'] }}">
                                <option value="">—</option>
                                @foreach ($f['enum'] as $option)
                                    <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                            @break
                        @case('date')
                            <input type="date" name="args[{{ $f['name'] }}]" id="f_{{ $f['name'] }}" value="{{ $value }}">
                            @break
                        @case('textarea')
                            <textarea name="args[{{ $f['name'] }}]" id="f_{{ $f['name'] }}">{{ $value }}</textarea>
                            @break
                        @case('number')
                            <input type="number" step="any" name="args[{{ $f['name'] }}]" id="f_{{ $f['name'] }}" value="{{ $value }}">
                            @break
                        @case('text')
                            <input type="text" name="args[{{ $f['name'] }}]" id="f_{{ $f['name'] }}" value="{{ $value }}">
                            @break
                        @case('unsupported')
                            <div class="help">Type <code>{{ $f['type'] ?? 'unknown' }}</code> has no form control; this parameter cannot be set from the console.</div>
                            @break
                    @endswitch

                    @if ($f['description'])<div class="help">{{ $f['description'] }}</div>@endif
                    @if ($f['pattern'])<div class="help">Format: <code>{{ $f['pattern'] }}</code></div>@endif
                </div>
            @endforeach
        @else
            <div class="field">
                <label for="other">Tool name <span class="req">*</span></label>
                <input type="text" name="other" id="other" value="{{ $other }}" placeholder="e.g. delete_farm">
                <div class="help">Not a registered tool, so there is no schema to build a form from. The call still goes through the gate.</div>
            </div>
            <div class="field">
                <label for="raw_args">Arguments <span class="opt">(optional JSON object)</span></label>
                <textarea name="raw_args" id="raw_args" placeholder='{"farm_id": "UG-KYA-012"}'>{{ request('raw_args') }}</textarea>
            </div>
        @endif

        <button type="submit">Run tool</button>
    </form>

    {{-- Result --}}
    @if (! empty($outcome['form_error']))
        <div class="banner refused"><strong>Form not sent</strong>{{ $outcome['form_error'] }}</div>
    @endif

    @if (! empty($outcome['notice']))
        <div class="banner notice">{{ $outcome['notice'] }}</div>
    @endif

    @if (! empty($outcome['result']))
        @php($result = $outcome['result'])

        @if ($result->ok)
            <div class="banner ok">
                <strong><span class="code">OK</span> — {{ $outcome['tool'] }}{{ ! empty($outcome['approved']) ? ' (approved)' : '' }}</strong>
                @if ($outcome['duration'] !== null)<span class="muted">{{ $outcome['duration'] }} ms</span>@endif
                <pre>{{ json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        @elseif ($result->code === \App\Services\Tools\ToolResult::APPROVAL_REQUIRED)
            <div class="banner pending">
                <strong><span class="code">APPROVAL_REQUIRED</span></strong>
                <p style="margin:.2rem 0 .7rem;font-size:1rem">{{ $result->message }}</p>

                <table>
                    <tr><th>Tool</th><td><code>{{ $result->pending['tool'] }}</code></td></tr>
                    @foreach ($result->pending['arguments'] as $key => $val)
                        <tr><th>{{ $key }}</th><td>{{ is_scalar($val) ? $val : json_encode($val) }}</td></tr>
                    @endforeach
                </table>

                <form method="POST" action="{{ route('tools.approve') }}" class="actions">
                    @csrf
                    <input type="hidden" name="token" value="{{ $outcome['token'] }}">
                    <button type="submit" name="decision" value="approve">Approve</button>
                    <button type="submit" name="decision" value="cancel" class="secondary">Cancel</button>
                </form>
            </div>
        @elseif ($result->code === \App\Services\Tools\ToolResult::INVALID_ARGUMENTS)
            <div class="banner refused">
                <strong><span class="code">INVALID_ARGUMENTS</span></strong>
                <ul class="errs">
                    @foreach ($errorLines as $line)<li>{{ $line }}</li>@endforeach
                </ul>
            </div>
        @else
            <div class="banner refused">
                <strong><span class="code">{{ $result->code }}</span></strong>
                {{ $result->message }}
            </div>
        @endif
    @endif

    {{-- Audit trail --}}
    <div class="card">
        <h2>Recent calls <span class="muted" style="font-weight:400;font-size:.85rem">— last 15 from today's tool log, newest first</span></h2>

        @if ($recent === [])
            <p class="muted" style="margin:0">No tool calls logged today.</p>
        @else
            <table>
                <tr><th>Time</th><th>Tool</th><th>Outcome</th><th>Duration</th><th>Approved</th></tr>
                @foreach ($recent as $entry)
                    @php($code = $entry['outcome'] ?? '?')
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($entry['recorded_at'])->timezone(config('app.timezone'))->format('H:i:s') }}</td>
                        <td><code>{{ $entry['tool'] }}</code></td>
                        <td>
                            <span class="tag {{ $code === 'OK' ? 'ok' : ($code === 'APPROVAL_REQUIRED' ? 'pending' : 'refused') }}">{{ $code }}</span>
                        </td>
                        <td>{{ $entry['duration_ms'] }} ms</td>
                        <td>{{ ! empty($entry['approved']) ? 'yes' : 'no' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

</div>
</body>
</html>
