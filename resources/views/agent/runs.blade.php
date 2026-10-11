<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AgriVisit — Agent runs</title>
    @include('tools._styles')
    <style>.wrap { max-width: 1100px; } .table-scroll { overflow-x: auto; }</style>
</head>
<body>
<div class="wrap">

    <header>
        @include('_nav')
        <h1>AgriVisit</h1>
        <div class="sub">All agent runs, newest first. Each links to its steps and its trace files.
            <a href="{{ route('agent.index') }}">New run →</a></div>
    </header>

    <div class="card table-scroll">
        @if ($runs->isEmpty())
            <p class="muted" style="margin:0">No runs yet.</p>
        @else
            <table>
                <tr><th>When</th><th>Goal</th><th>Status</th><th>Stop reason</th><th>Iterations</th><th>Tokens (in / out)</th></tr>
                @foreach ($runs as $r)
                    @php($tag = match ($r->stop_reason) {
                        'GOAL_REACHED' => 'ok',
                        'AWAITING_APPROVAL' => 'pending',
                        'GUARD_REFUSED', 'MODEL_ERROR', 'REPEATED_FAILURE' => 'refused',
                        default => 'notice',
                    })
                    <tr>
                        <td class="muted" style="white-space:nowrap">{{ $r->created_at->timezone(config('app.timezone'))->format('j M Y H:i') }}</td>
                        <td><a href="{{ route('agent.runs.show', $r->run_id) }}">{{ \Illuminate\Support\Str::limit($r->goal, 80) }}</a></td>
                        <td>{{ $r->status === 'AWAITING_APPROVAL' ? 'waiting on officer' : ($r->approvalExpired() ? 'closed — approval expired' : ($r->stop_reason ? 'closed' : $r->status)) }}</td>
                        <td><span class="tag {{ $tag }}">{{ $r->stop_reason ?? $r->status }}</span></td>
                        <td>{{ $r->iterations }} / {{ $r->limits['max_iterations'] ?? '?' }}</td>
                        <td>{{ number_format($r->input_tokens) }} / {{ number_format($r->output_tokens) }}</td>
                    </tr>
                @endforeach
            </table>
            <div style="margin-top:.8rem">{{ $runs->links() }}</div>
        @endif
    </div>

</div>
</body>
</html>
