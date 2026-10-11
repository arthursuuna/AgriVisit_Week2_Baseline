<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AgriVisit — Agent</title>
    @include('tools._styles')
</head>
<body>
<div class="wrap">

    <header>
        @include('_nav')
        <h1>AgriVisit</h1>
        <div class="sub">Agent — Week 5. State a goal; the agent looks things up, searches the guidance corpus and
            proposes an action. It stops for your approval before anything is written.
            <a href="{{ route('agent.runs.index') }}">All runs →</a></div>
    </header>

    <form method="POST" action="{{ route('agent.start') }}" class="card" id="run-form">
        @csrf
        <h2>Goal</h2>
        <div class="field">
            <textarea name="goal" id="goal" style="min-height:70px" maxlength="1000" required
                placeholder="e.g. Prepare and schedule a follow-up visit for farm UG-KYA-012">{{ old('goal') }}</textarea>
            <div class="help">
                Examples: “Prepare and schedule a follow-up visit for farm UG-KYA-012” ·
                “Prepare me for a visit to farm UG-KYA-012” ·
                “What guidance is there on the bean yields at UG-KYA-012?”
            </div>
            @error('goal')<div class="help" style="color:var(--danger)">{{ $message }}</div>@enderror
        </div>
        <div class="row">
            <button type="submit" id="run-button">Run</button>
            <span class="muted" style="font-size:.88rem">
                Running as <strong style="display:inline">{{ config('agrivisit.officer.name') }}</strong>
                (<code>{{ config('agrivisit.officer.id') }}</code>) — configured, not authenticated.
                A run takes 10–25 seconds and uses API quota.
            </span>
        </div>
    </form>

    @if ($recent->isNotEmpty())
        <div class="card">
            <h2>Recent runs</h2>
            <table>
                <tr><th>Goal</th><th>Stop reason</th><th>When</th></tr>
                @foreach ($recent as $r)
                    <tr>
                        <td><a href="{{ route('agent.runs.show', $r->run_id) }}">{{ \Illuminate\Support\Str::limit($r->goal, 70) }}</a></td>
                        <td><span class="code">{{ $r->stop_reason ?? $r->status }}</span></td>
                        <td class="muted">{{ $r->created_at->timezone(config('app.timezone'))->format('j M H:i') }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

</div>
<script>
    // A run is synchronous; say so while the page waits.
    document.getElementById('run-form').addEventListener('submit', function () {
        var b = document.getElementById('run-button');
        b.disabled = true;
        b.textContent = 'Running… (10–25 s)';
    });
</script>
</body>
</html>
