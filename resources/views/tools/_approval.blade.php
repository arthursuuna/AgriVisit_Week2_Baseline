{{--
    The approval panel. $held comes from ApprovalService::hold(); the summary
    and preview shown here are the exact values stored and later recorded.
    Expects: $held, $action (route to post the decision to), $expiry (minutes).
--}}
<div class="banner pending approval">
    <strong>
        <span class="code">APPROVAL_REQUIRED</span>
        <span class="origin">— {{ $held['origin'] === 'model' ? 'Proposed by the assistant' : 'Entered manually' }}</span>
    </strong>

    <p class="summary">{{ $held['summary'] }}</p>

    <table class="preview">
        @foreach ($held['preview'] as $row)
            <tr @class(['warning' => ($row['level'] ?? 'normal') === 'warning'])>
                <th>{{ $row['label'] }}</th>
                <td>
                    <span @class(['unconfirmed' => $row['value'] === \App\Services\Tools\ApprovalPreview::UNCONFIRMED])>{{ $row['value'] }}</span>
                    @if ($row['note'])<div class="help">{{ $row['note'] }}</div>@endif
                </td>
            </tr>
        @endforeach
    </table>

    <details>
        <summary>Raw arguments</summary>
        <pre>{{ json_encode(['tool' => $held['tool'], 'arguments' => $held['arguments']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </details>

    <form method="POST" action="{{ $action }}">
        @csrf
        <input type="hidden" name="token" value="{{ $held['token'] }}">
        <div class="reason">
            <label for="reason_{{ substr($held['token'], 0, 8) }}">Reason for rejecting <span class="opt">(optional)</span></label>
            <input type="text" name="reason" id="reason_{{ substr($held['token'], 0, 8) }}" maxlength="500"
                placeholder="e.g. Wrong farm, or the farmer has asked to wait">
        </div>
        <div class="actions">
            <button type="submit" name="decision" value="approve">Approve</button>
            <button type="submit" name="decision" value="reject" class="secondary">Reject</button>
        </div>
    </form>

    <p class="expiry">
        This approval expires in {{ $expiry }} minutes, at
        {{ \Illuminate\Support\Carbon::createFromTimestamp($held['expires_at'])->timezone(config('app.timezone'))->format('H:i') }}.
        After that the request must be made again. Your decision, either way, is recorded as
        <strong style="display:inline">{{ config('agrivisit.officer.name') }}</strong> (<code>{{ config('agrivisit.officer.id') }}</code>).
    </p>
</div>
