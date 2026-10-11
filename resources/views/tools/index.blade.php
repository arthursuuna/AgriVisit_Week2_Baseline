<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AgriVisit — Tool Console</title>
    @include('tools._styles')
</head>
<body>
<div class="wrap">

    <header>
        <span class="nav"><a href="{{ route('tools.audit') }}">Approval audit →</a> · <a href="{{ route('checklist.index') }}">← Visit Prep Console</a></span>
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

    @if (! empty($ask['decision']))
        @include('tools._decision', ['decision' => $ask['decision']])
    @endif

    @if (! empty($ask['result']))
        @php($session = $ask['result'])
        @php($toolCode = $session->toolResult ? ($session->toolResult->code ?? 'OK') : null)

        @if ($session->status === \App\Services\Tools\SessionResult::AWAITING_APPROVAL && ! empty($ask['held']))
            @include('tools._approval', ['held' => $ask['held'], 'action' => route('tools.ask.approve')])
        @else
            <div class="banner {{ in_array($session->status, ['refused', 'too_many_calls', 'model_error'], true) ? 'refused' : 'ok' }}">
                <strong>
                    @if ($session->status === 'refused')
                        Refused before any model call — {{ $session->refusalCategory }}
                    @elseif ($session->status === 'too_many_calls')
                        More than one tool call proposed — nothing was run
                    @elseif ($session->status === 'model_error')
                        Model failure — the model could not answer
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

    @if (! empty($outcome['decision']))
        @include('tools._decision', ['decision' => $outcome['decision']])
    @endif

    @if (! empty($outcome['result']))
        @php($result = $outcome['result'])

        @if ($result->ok)
            <div class="banner ok">
                <strong><span class="code">OK</span> — {{ $outcome['tool'] }}{{ ! empty($outcome['approved']) ? ' (approved)' : '' }}</strong>
                @if ($outcome['duration'] !== null)<span class="muted">{{ $outcome['duration'] }} ms</span>@endif
                <pre>{{ json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        @elseif ($result->code === \App\Services\Tools\ToolResult::APPROVAL_REQUIRED && ! empty($outcome['held']))
            @include('tools._approval', ['held' => $outcome['held'], 'action' => route('tools.approve')])
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
