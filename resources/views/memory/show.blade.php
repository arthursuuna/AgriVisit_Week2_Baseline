@php
    use App\Models\FarmMemory;
    $kinds = [FarmMemory::APPROVED => 'Approved', FarmMemory::DECLINED => 'Declined', FarmMemory::LAPSED => 'Lapsed'];
    $officer = fn (string $id) => $id === config('agrivisit.officer.id') ? config('agrivisit.officer.name') . " ({$id})" : $id;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AgriVisit — Memory for {{ $farmId }}</title>
    @include('tools._styles')
    <style>
        .entry { border-left: 3px solid var(--line); padding: .3rem 0 .3rem .9rem; margin-bottom: 1.2rem; }
        .entry.declined { border-left-color: var(--warn); }
        .entry.approved { border-left-color: var(--accent); }
        .entry-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: .2rem .7rem; }
        .entry-head .kind { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
                            padding: .05rem .4rem; border-radius: 4px; border: 1px solid currentColor; color: var(--muted); }
        .entry dl { margin: .45rem 0 0; font-size: .9rem; }
        .entry dl div { display: flex; gap: .6rem; padding: .12rem 0; }
        .entry dt { flex: 0 0 9rem; color: var(--muted); }
        .entry dd { margin: 0; min-width: 0; overflow-wrap: break-word; }
        .reason { white-space: pre-wrap; }
        .entry form { margin-top: .5rem; }
        .entry form button { padding: .3rem .8rem; font-size: .85rem; }
    </style>
</head>
<body>
<div class="wrap">

    <header>
        @include('_nav')
        <h1>AgriVisit</h1>
        <div class="sub">Memory for farm <span class="code">{{ $farmId }}</span> · <a href="{{ route('agent.runs.index') }}">All runs</a></div>
    </header>

    @if (session('deleted'))
        <div class="banner ok">Deleted <span class="code">{{ session('deleted') }}</span>. The assistant will not recall it again.
            Runs that already used it keep their record of it.</div>
    @endif

    <div class="card">
        <h2>What the assistant can recall about this farm</h2>
        <p class="muted" style="margin-top:0">This is what the assistant can recall about this farm, and nothing else. It reads these
            only by calling <code>recall_farm_history</code>, which appears as a step on the run page, and cites each by its reference.
            Decisions from the last {{ $months }} months are recalled; older ones are listed below and are not.</p>

        @forelse ($recallable as $m)
            @include('memory._entry', ['m' => $m])
        @empty
            <p style="margin:0">Nothing. The assistant has no past decisions to recall for this farm.</p>
        @endforelse
    </div>

    @if ($expired->isNotEmpty())
        <div class="card">
            <h2>Held, but no longer recalled</h2>
            <p class="muted" style="margin-top:0">Older than {{ $months }} months. The assistant does not see these, but they are still
                stored: nothing deletes them automatically. Delete them here when they are no longer needed.</p>
            @foreach ($expired as $m)
                @include('memory._entry', ['m' => $m])
            @endforeach
        </div>
    @endif

    @if ($deletions->isNotEmpty())
        <div class="card">
            <h2>Deleted</h2>
            <table>
                <tr><th>Entry</th><th>Deleted by</th><th>When</th></tr>
                @foreach ($deletions as $d)
                    <tr><td><span class="code">{{ $d->memory_ref }}</span></td><td>{{ $officer($d->deleted_by) }}</td>
                        <td>{{ $d->deleted_at->timezone(config('app.timezone'))->format('j M Y H:i') }}</td></tr>
                @endforeach
            </table>
            <p class="muted" style="margin-bottom:0">What a deleted entry said is not kept here. The decision itself stays in the
                <a href="{{ route('tools.audit') }}">approval audit</a>, and the traces of runs that recalled it are unchanged.</p>
        </div>
    @endif

</div>
</body>
</html>
