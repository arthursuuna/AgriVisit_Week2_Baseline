<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AgriVisit — Approval Audit</title>
    @include('tools._styles')
    <style>
        .wrap { max-width: 1100px; }
        .filters { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; font-size: .88rem; margin-bottom: 1rem; }
        .filters a {
            border: 1px solid var(--line); color: var(--ink); background: #fff;
            border-radius: 20px; padding: .2rem .8rem; text-decoration: none;
        }
        .filters a.on { border-color: var(--accent); color: var(--accent); background: var(--accent-soft); font-weight: 600; }
        tr.record { cursor: pointer; }
        tr.record:hover td { background: #f7f9fb; }
        tr.record:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; }
        tr.detail > td { background: #fbfcfd; padding: .8rem 1rem 1rem; }
        tr.detail h3 { font-size: .9rem; margin: 0 0 .4rem; }
        tr.detail table.preview th { width: 25%; }
        .caret { color: var(--muted); font-size: .75rem; }
        .identity { font-size: .82rem; }
        .table-scroll { overflow-x: auto; }
    </style>
</head>
<body>
<div class="wrap">

    <header>
        @include('_nav')
        <h1>AgriVisit</h1>
        <div class="sub">Approval audit — the latest 50 decisions on write actions, newest first: which the assistant
            proposed, which a person entered, and what was approved, rejected or left to expire.</div>
    </header>

    <div class="banner notice identity">
        <strong>Identity is configured, not authenticated.</strong>
        “Decided by” is the officer set in configuration (<code>OFFICER_ID</code>), not a signed-in user. Until this
        application has authentication, this record shows what was decided and what was shown, but not who verifiably
        decided it.
    </div>

    <div class="card">
        <div class="filters">
            <span class="muted">Decision:</span>
            <a href="{{ route('tools.audit') }}" @class(['on' => $filter === null])>All ({{ $counts->sum() }})</a>
            @foreach ($decisions as $d)
                <a href="{{ route('tools.audit', ['decision' => $d]) }}" @class(['on' => $filter === $d])>{{ ucfirst($d) }} ({{ $counts[$d] ?? 0 }})</a>
            @endforeach
        </div>

        @if ($records->isEmpty())
            <p class="muted" style="margin:0">No {{ $filter ? $filter . ' ' : '' }}decisions recorded yet.</p>
        @else
            <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th></th><th>Ref</th><th>Time</th><th>Tool</th><th>Origin</th><th>Decision</th>
                        <th>Decided by</th><th>Summary shown</th><th>Result</th><th>Created</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($records as $r)
                    <tr class="record" tabindex="0" aria-expanded="false" aria-controls="detail-{{ $r->id }}" data-record="{{ $r->approval_ref }}">
                        <td class="caret">▸</td>
                        <td><code>{{ $r->approval_ref }}</code></td>
                        <td style="white-space:nowrap">{{ $r->decided_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                        <td><code>{{ $r->tool_name }}</code></td>
                        <td>{{ $r->origin === 'model' ? 'Assistant' : 'Manual' }}</td>
                        <td><span class="tag {{ ['approved' => 'ok', 'rejected' => 'notice', 'expired' => 'refused'][$r->decision] }}">{{ $r->decision }}</span></td>
                        <td>{{ $r->decided_by }}</td>
                        <td>{{ $r->summary_shown }}</td>
                        <td>@if ($r->result_code)<span class="tag {{ $r->result_code === 'OK' ? 'ok' : 'refused' }}">{{ $r->result_code }}</span>@else<span class="muted">—</span>@endif</td>
                        <td>{!! $r->result_ref ? '<code>' . e($r->result_ref) . '</code>' : '<span class="muted">—</span>' !!}</td>
                    </tr>
                    <tr class="detail" id="detail-{{ $r->id }}" hidden>
                        <td></td>
                        <td colspan="9">
                            <h3>Preview the officer saw</h3>
                            <table class="preview">
                                @foreach ($r->preview_shown ?? [] as $row)
                                    <tr @class(['warning' => ($row['level'] ?? 'normal') === 'warning'])>
                                        <th>{{ $row['label'] }}</th>
                                        <td>
                                            <span @class(['unconfirmed' => $row['value'] === \App\Services\Tools\ApprovalPreview::UNCONFIRMED])>{{ $row['value'] }}</span>
                                            @if (! empty($row['note']))<div class="help">{{ $row['note'] }}</div>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                            @if ($r->reject_reason)
                                <p style="margin:.7rem 0 0"><strong>Reason given:</strong> {{ $r->reject_reason }}</p>
                            @endif

                            <h3 style="margin-top:.9rem">Raw arguments</h3>
                            <pre>{{ json_encode($r->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>

                            <div class="meta">
                                Session <code>{{ $r->session_id ?? '—' }}</code> · Gate call <code>{{ $r->call_id ?? '—' }}</code>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

</div>
<script>
    document.querySelectorAll('tr.record').forEach(function (row) {
        function toggle() {
            var detail = document.getElementById(row.getAttribute('aria-controls'));
            var open = detail.hidden;
            detail.hidden = !open;
            row.setAttribute('aria-expanded', open ? 'true' : 'false');
            row.querySelector('.caret').textContent = open ? '▾' : '▸';
        }
        row.addEventListener('click', toggle);
        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
        });
    });
</script>
</body>
</html>
