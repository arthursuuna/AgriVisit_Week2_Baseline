{{--
    What became of a decision. Expects: $decision (ApprovalOutcome).
    An approved call's tool result is shown by the caller's own result panel.
--}}
@php($record = $decision->record)

@switch($decision->status)
    @case(\App\Services\Tools\ApprovalOutcome::APPROVED)
        <div class="banner ok">
            <strong>Approved — recorded as <span class="code">{{ $record->approval_ref }}</span></strong>
            @if ($record->result_ref)
                Created <span class="code">{{ $record->result_ref }}</span>.
            @elseif ($record->result_code && $record->result_code !== 'OK')
                The tool did not complete (<span class="code">{{ $record->result_code }}</span>); nothing was written.
            @endif
            <span class="muted">Decided by {{ $record->decided_by }} at {{ $record->decided_at->timezone(config('app.timezone'))->format('H:i:s') }}.</span>
        </div>
        @break

    @case(\App\Services\Tools\ApprovalOutcome::REJECTED)
        <div class="banner notice">
            <strong>Rejected — nothing was written</strong>
            The <code>{{ $record->tool_name }}</code> call was discarded. The decision is recorded as
            <span class="code">{{ $record->approval_ref }}</span>.
            @if ($record->reject_reason)<br><span class="muted">Reason: {{ $record->reject_reason }}</span>@endif
        </div>
        @break

    @case(\App\Services\Tools\ApprovalOutcome::EXPIRED)
        <div class="banner refused">
            <strong>This request timed out — nothing was written</strong>
            Approval must be given within {{ config('agrivisit.tools.approval_expiry_minutes') }} minutes of the
            proposal, because what it was based on may have changed. It has been recorded as
            <span class="code">{{ $record->approval_ref }}</span> (expired). Ask again to get a fresh proposal.

            @if ($decision->origin() === 'model' && $decision->request())
                <form method="POST" action="{{ route('tools.ask') }}" class="actions">
                    @csrf
                    <input type="hidden" name="question" value="{{ $decision->request() }}">
                    <button type="submit">Ask again: “{{ \Illuminate\Support\Str::limit($decision->request(), 60) }}”</button>
                </form>
            @else
                <form method="POST" action="{{ route('tools.run') }}" class="actions">
                    @csrf
                    <input type="hidden" name="tool" value="{{ $decision->tool() }}">
                    @foreach ($decision->arguments() as $key => $val)
                        <input type="hidden" name="args[{{ $key }}]" value="{{ is_scalar($val) ? $val : json_encode($val) }}">
                    @endforeach
                    <button type="submit">Submit the {{ $decision->tool() }} call again</button>
                </form>
            @endif
        </div>
        @break

    @default
        <div class="banner notice">
            That approval has expired or was already used. Nothing was written.
            @if ($decision->status === \App\Services\Tools\ApprovalOutcome::ALREADY_USED)
                <span class="muted">It had already been decided; see the <a href="{{ route('tools.audit') }}">audit page</a>.</span>
            @endif
        </div>
@endswitch
