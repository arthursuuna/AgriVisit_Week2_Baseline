@php
    use App\Services\Agent\StopReason;
    $reason = $run->stop_reason ?? $run->status;
    $limitStops = [StopReason::ITERATION_CAP, StopReason::NO_PROGRESS, StopReason::REPEATED_FAILURE, StopReason::RUNTIME_BUDGET];
    $latestTrace = collect($run->trace_files)->last();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AgriVisit — Agent run</title>
    @include('tools._styles')
    <style>
        .step { border-left: 3px solid var(--line); padding: .2rem 0 .2rem .9rem; margin-bottom: 1.1rem; }
        .step.failed { border-left: 5px solid var(--danger); background: var(--danger-soft); padding: .5rem .9rem; }
        .step.held { border-left-color: var(--warn); }
        .step.declined { border-left-color: var(--muted); }
        .step.officer.ok { border-left-color: var(--accent); }
        .step-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: .2rem .6rem; font-size: .92rem; }
        .step-head .num { font-weight: 700; }
        .step-head .dur { margin-left: auto; color: var(--muted); font-size: .85rem; }
        .mark { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
                padding: .05rem .4rem; border-radius: 4px; border: 1px solid currentColor; }
        .mark.failed { color: var(--danger); }
        .mark.held { color: var(--warn); }
        .mark.declined { color: var(--muted); }
        .plan { white-space: pre-wrap; margin: .35rem 0 .45rem; }
        .args { margin: 0; font-size: .88rem; }
        .args div { display: flex; gap: .6rem; padding: .1rem 0; }
        .args dt { flex: 0 0 8rem; color: var(--muted); font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: .82rem; }
        .args dd { margin: 0; min-width: 0; white-space: pre-wrap; overflow-wrap: break-word; }
        .step details { margin-top: .3rem; font-size: .8rem; }
        .step details pre { white-space: pre-wrap; overflow-wrap: break-word; margin: .3rem 0 0; }
        .step .message { margin: .45rem 0 0; font-size: .9rem; }
        .final { white-space: pre-wrap; }
    </style>
</head>
<body>
<div class="wrap">

    <header>
        @include('_nav')
        <h1>AgriVisit</h1>
        <div class="sub">Agent run <span class="code">{{ $run->run_id }}</span> ·
            <a href="{{ route('agent.runs.index') }}">All runs</a> · <a href="{{ route('agent.index') }}">New run →</a></div>
    </header>

    @if (session('decision'))
        @php([$kind, $text] = \App\Http\Controllers\AgentController::decisionText(session('decision')))
        <div class="banner {{ $kind }}">{{ $text }}</div>
    @endif

    <div class="card">
        <h2>Goal</h2>
        <p style="margin:0">{{ $run->goal }}</p>
    </div>

    <div class="card">
        <h2>Steps</h2>
        @forelse (\App\Support\AgentStepView::forRun($run) as $step)
            <div @class(['step', $step['kind'], 'officer' => $step['officer']])>
                <div class="step-head">
                    <span class="num">Step {{ $step['iteration'] }}{{ $step['officer'] ? ' · officer decision' : '' }}</span>
                    <span class="code">{{ $step['tool'] ?? '— answer' }}</span>
                    @if ($step['mark'])<span class="mark {{ $step['kind'] }}">{{ $step['mark'] }}</span>@endif
                    <span class="code">{{ $step['label'] }}</span>
                    @if ($step['tool'])<span class="dur">{{ number_format($step['durationMs']) }} ms</span>@endif
                </div>
                @if ($step['answer'])
                    <p class="muted" style="margin:.35rem 0 0">The agent's answer is in the <a href="#summary">Finished</a> summary below.</p>
                @else
                    <div class="plan">{{ $step['plan'] !== '' ? $step['plan'] : '(no plan stated)' }}</div>
                @endif
                @if ($step['tool'] && $step['arguments'] !== [])
                    <dl class="args">
                        @foreach ($step['arguments'] as $name => $value)
                            <div><dt>{{ $name }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                    <details>
                        <summary>Raw arguments</summary>
                        <pre>{{ json_encode($step['rawArguments'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </details>
                @endif
                @if ($step['message'])
                    <p class="message">{{ $step['message'] }}</p>
                @endif
            </div>
        @empty
            <p class="muted" style="margin:0">No steps: the run ended before the model was called.</p>
        @endforelse
    </div>

    {{-- One treatment per stop reason --}}
    @if ($reason === StopReason::AWAITING_APPROVAL && $run->approval)
        <p style="margin:0 0 .5rem;font-weight:600">Nothing has been written yet.</p>
        @include('tools._approval', ['held' => $run->approval, 'action' => route('agent.runs.decide', $run->run_id)])

    @elseif ($reason === StopReason::GUARD_REFUSED)
        <div class="banner refused">
            <strong>Refused — this topic is outside what AgriVisit can help with</strong>
            {{ $run->summary }}
            <div class="muted" style="margin-top:.4rem">The run has ended. The refusal applies however the request is worded.</div>
        </div>

    @elseif ($reason === StopReason::REJECTED && $run->approvalExpired())
        <div class="banner notice">
            <strong>Expired — the request was never decided and the run is closed</strong>
            {{ $run->summary }}
        </div>

    @elseif ($reason === StopReason::REJECTED)
        <div class="banner notice">
            <strong>Declined — the run is closed</strong>
            {{ $run->summary }}
        </div>

    @elseif (in_array($reason, $limitStops, true))
        <div class="banner pending">
            <strong>
                @switch($reason)
                    @case(StopReason::ITERATION_CAP) Stopped at the iteration limit ({{ $run->limits['max_iterations'] }}) @break
                    @case(StopReason::NO_PROGRESS) Stopped: the agent repeated a call that could not return anything new @break
                    @case(StopReason::REPEATED_FAILURE) Stopped after {{ $run->limits['max_consecutive_failures'] }} tool failures in a row @break
                    @case(StopReason::RUNTIME_BUDGET) Stopped at the runtime budget ({{ $run->limits['max_runtime_seconds'] }} s of work) @break
                @endswitch
            </strong>
            {{ $run->summary }}
            <div class="muted" style="margin-top:.4rem">The steps above are kept; nothing was written.</div>
        </div>

    @elseif ($reason === StopReason::MODEL_ERROR)
        <div class="banner refused"><strong>The model could not complete the run</strong>{{ $run->summary }}</div>

    @elseif ($reason === StopReason::GOAL_REACHED)
        <div class="banner ok" id="summary">
            <strong>Finished</strong>
            <div class="final">{{ $run->summary }}</div>
        </div>
    @endif

    <div class="card">
        <table>
            <tr><th>Stop reason</th><td><span class="code">{{ $reason }}</span></td></tr>
            <tr><th>Iterations</th><td>{{ $run->iterations }} of {{ $run->limits['max_iterations'] }}</td></tr>
            <tr><th>Tokens</th><td>{{ number_format($run->input_tokens) }} in / {{ number_format($run->output_tokens) }} out</td></tr>
            <tr><th>Active time</th><td>{{ number_format($run->active_seconds, 1) }} s of {{ $run->limits['max_runtime_seconds'] }} s
                <span class="muted">(work only; time waiting for a decision is not counted)</span></td></tr>
            <tr><th>Model / prompt</th><td><code>{{ $run->model }}</code> · <code>agent/{{ $run->prompt_version }}</code></td></tr>
            <tr><th>Trace</th><td>
                @foreach ($run->trace_files as $file)
                    <a href="{{ route('agent.runs.trace', [$run->run_id, $file]) }}">{{ $file }}</a>@if (! $loop->last) → @endif
                @endforeach
            </td></tr>
        </table>
    </div>

</div>
</body>
</html>
