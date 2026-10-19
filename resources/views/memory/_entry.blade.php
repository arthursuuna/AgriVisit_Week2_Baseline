{{-- One memory entry. Expects $m (FarmMemory), $kinds, $officer, $runs, $farmId. --}}
<div class="entry {{ $m->kind }}" id="{{ $m->memory_ref }}">
    <div class="entry-head">
        <span class="code"><strong>{{ $m->memory_ref }}</strong></span>
        <span class="kind">{{ $kinds[$m->kind] }}</span>
    </div>
    <dl>
        <div><dt>What was decided</dt><dd>{{ $m->summary }}</dd></div>
        <div><dt>Proposed for</dt><dd>{{ $m->proposed() ?? '—' }}</dd></div>
        <div><dt>Decided</dt><dd>{{ $m->occurred_at->timezone(config('app.timezone'))->format('j M Y H:i') }}{{ $m->kind === \App\Models\FarmMemory::LAPSED ? ' (the window closed)' : '' }}</dd></div>
        <div><dt>Officer's reason</dt><dd class="reason">{{ $m->details['officer_reason'] ?? '—' }}</dd></div>
        <div><dt>Decided by</dt><dd>{{ $officer($m->officer_id) }}</dd></div>
        <div><dt>From run</dt><dd>
            @if (isset($runs[$m->run_id]))
                <a href="{{ route('agent.runs.show', $m->run_id) }}"><span class="code">{{ $m->run_id }}</span></a>
            @else
                <span class="code">{{ $m->run_id }}</span> <span class="muted">(run no longer stored)</span>
            @endif
        </dd></div>
    </dl>
    <form method="POST" action="{{ route('memory.destroy', [$farmId, $m->memory_ref]) }}">
        @csrf
        @method('DELETE')
        <button type="submit" class="secondary">Delete {{ $m->memory_ref }}</button>
    </form>
</div>
