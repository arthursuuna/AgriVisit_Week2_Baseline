<?php

namespace App\Http\Controllers;

use App\Models\ToolApproval;
use App\Services\Tools\ApprovalOutcome;
use App\Services\Tools\ApprovalService;
use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolInvoker;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolResult;
use App\Services\Tools\ToolSession;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Browser front end for the Week 4 tool layer.
 *
 * Drives ToolInvoker exactly as tinker does: no model, and no validation of
 * its own. Whatever the officer types reaches the invoker, so the page shows
 * the real refusals. Pending write calls are held server-side by
 * ApprovalService and replayed verbatim on approval, never rebuilt from the
 * form. Every decision, either way, is recorded in tool_approvals.
 */
class ToolConsoleController extends Controller
{
    /** Select value meaning "use the free-text tool name instead". */
    public const OTHER = '__other';

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolInvoker $invoker,
        private readonly ToolCallLog $log,
        private readonly ApprovalService $approvals,
    ) {
    }

    public function index(Request $request): View
    {
        return $this->page(
            (string) $request->query('tool', ($this->registry->all()[0] ?? null)?->name() ?? self::OTHER),
            (string) $request->query('other', ''),
            (array) $request->query('args', []),
        );
    }

    public function run(Request $request): View
    {
        $selected = (string) $request->input('tool', '');
        $other    = trim((string) $request->input('other', ''));
        $posted   = (array) $request->input('args', []);
        $name     = $selected === self::OTHER ? $other : $selected;

        [$args, $formError] = $this->collectArguments($name, $posted, (string) $request->input('raw_args', ''));

        if ($formError !== null) {
            return $this->page($selected, $other, $posted, ['form_error' => $formError]);
        }

        // Never approved here: approval only ever comes from approve().
        $result   = $this->invoker->invoke($name, $args);
        $duration = $this->lastDuration();
        $held     = null;

        if ($result->code === ToolResult::APPROVAL_REQUIRED && $result->pending !== null) {
            $held = $this->approvals->hold($result->pending, (string) $result->message, ToolApproval::ORIGIN_MANUAL);
        }

        return $this->page($selected, $other, $posted, [
            'tool'     => $name,
            'args'     => $args,
            'result'   => $result,
            'duration' => $duration,
            'held'     => $held,
        ]);
    }

    public function approve(Request $request): View
    {
        $decision = $this->decide($request, function (array $pending) {
            // The exact tool and arguments the invoker returned, replayed verbatim.
            return $this->invoker->invoke($pending['tool'], $pending['arguments'], true);
        });

        if ($decision->status !== ApprovalOutcome::APPROVED) {
            return $this->page($decision->tool() ?? self::OTHER, '', $decision->arguments(), ['decision' => $decision]);
        }

        return $this->page($decision->tool(), '', $decision->arguments(), [
            'tool'     => $decision->tool(),
            'args'     => $decision->arguments(),
            'result'   => $decision->toolResult,
            'duration' => $this->lastDuration(),
            'approved' => true,
            'decision' => $decision,
        ]);
    }

    /** Approve or reject, read from the posted form. 'cancel' is a rejection. */
    private function decide(Request $request, callable $execute): ApprovalOutcome
    {
        return $this->approvals->decide(
            (string) $request->input('token', ''),
            $request->input('decision') === 'approve' ? 'approve' : 'reject',
            $request->input('reason'),
            $execute,
        );
    }

    /**
     * Turns form fields into invoker arguments, typed by the tool's schema.
     *
     * Empty fields are dropped, required or not, so a blank required field
     * reaches the invoker as missing and is reported as such. Nothing else is
     * checked here.
     *
     * @return array{0: array, 1: ?string} arguments, and a form error if the raw JSON was unreadable
     */
    private function collectArguments(string $name, array $posted, string $raw): array
    {
        $tool = $this->registry->get($name);

        // An unregistered name has no schema, so arguments come from the raw JSON box.
        if ($tool === null) {
            if (trim($raw) === '') {
                return [[], null];
            }

            $decoded = json_decode($raw, true);

            return is_array($decoded)
                ? [$decoded, null]
                : [[], 'Arguments must be a JSON object, e.g. {"farm_id": "UG-KYA-012"}.'];
        }

        $args = [];

        foreach ($tool->inputSchema()['properties'] ?? [] as $property => $schema) {
            $type  = self::primaryType($schema);
            $value = $posted[$property] ?? null;

            if ($type === 'boolean') {
                $args[$property] = $value !== null;

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $args[$property] = match ($type) {
                'integer' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value,
                'number'  => is_numeric($value) ? $value + 0 : $value,
                default   => $value,
            };
        }

        return [$args, null];
    }

    /**
     * The Ask panel: the model proposes a call, ToolSession routes it through
     * the same gate. Costs quota; the manual picker below does not.
     */
    public function ask(Request $request, ToolSession $session): View
    {
        $question = trim((string) $request->input('question', ''));

        if ($question === '') {
            return $this->askPage(['question' => '', 'error' => 'Type a request first.']);
        }

        $result = $session->handle($question);
        $held   = null;

        if ($result->pending !== null) {
            $held = $this->approvals->hold($result->pending, $result->pending['summary'],
                ToolApproval::ORIGIN_MODEL, $result->sessionId, $question);
        }

        return $this->askPage(['question' => $question, 'result' => $result, 'held' => $held]);
    }

    public function askApprove(Request $request, ToolSession $session): View
    {
        // The held call, exactly as the gate returned it. Approval is this click, never the model.
        $decision = $this->decide($request, fn (array $pending) => $session->handle($pending['request'], $pending));

        if ($decision->status !== ApprovalOutcome::APPROVED) {
            return $this->askPage(['question' => $decision->request() ?? '', 'decision' => $decision]);
        }

        return $this->askPage([
            'question' => $decision->request(),
            'result'   => $decision->executed,
            'approved' => true,
            'decision' => $decision,
        ]);
    }

    /**
     * The supervisor's view: the latest decisions, who made them and what they
     * were shown. Story 9: which actions the assistant proposed, which a person
     * entered, and what was approved or rejected.
     */
    public function audit(Request $request): View
    {
        $decisions = [ToolApproval::APPROVED, ToolApproval::REJECTED, ToolApproval::EXPIRED];
        $filter    = in_array($request->query('decision'), $decisions, true) ? $request->query('decision') : null;

        return view('tools.audit', [
            'records'   => ToolApproval::query()
                ->when($filter, fn ($q) => $q->where('decision', $filter))
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'filter'    => $filter,
            'decisions' => $decisions,
            'counts'    => ToolApproval::query()->selectRaw('decision, count(*) as n')->groupBy('decision')->pluck('n', 'decision'),
        ]);
    }

    private function askPage(array $ask): View
    {
        return $this->page(($this->registry->all()[0] ?? null)?->name() ?? self::OTHER, '', [], [], $ask);
    }

    private function page(string $selected, string $other, array $values, array $outcome = [], array $ask = []): View
    {
        $tool = $selected === self::OTHER ? null : $this->registry->get($selected);

        return view('tools.index', [
            'tools'      => $this->registry->all(),
            'selected'   => $tool ? $selected : self::OTHER,
            'other'      => $tool ? '' : ($selected === self::OTHER ? $other : $selected),
            'tool'       => $tool,
            'fields'     => $tool ? $this->fields($tool->inputSchema()) : [],
            'values'     => $values,
            'outcome'    => $outcome,
            'ask'        => $ask,
            'expiry'     => $this->approvals->expiryMinutes(),
            'errorLines' => $this->errorLines($outcome['result'] ?? null),
            'recent'     => array_slice(array_reverse($this->log->entries()), 0, 15),
            'refusals'   => $this->refusals(),
        ]);
    }

    /** One form control per schema property. */
    private function fields(array $schema): array
    {
        $required = $schema['required'] ?? [];
        $fields   = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $type = self::primaryType($property);

            $fields[] = [
                'name'        => $name,
                'required'    => in_array($name, $required, true),
                'description' => $property['description'] ?? null,
                'pattern'     => $property['pattern'] ?? null,
                'enum'        => $property['enum'] ?? null,
                'control'     => match (true) {
                    $type === 'string' && isset($property['enum'])         => 'select',
                    $type === 'string' && ($property['format'] ?? null) === 'date' => 'date',
                    $type === 'string' && ($property['maxLength'] ?? 0) > 300      => 'textarea',
                    $type === 'string'                                     => 'text',
                    $type === 'integer', $type === 'number'                => 'number',
                    $type === 'boolean'                                    => 'checkbox',
                    default                                                => 'unsupported',
                },
                'type'        => $type,
            ];
        }

        return $fields;
    }

    /**
     * INVALID_ARGUMENTS carries every error in one message. Each error starts
     * with one of the validator's fixed openings, so split there.
     *
     * @return list<string>
     */
    private function errorLines(?ToolResult $result): array
    {
        if ($result?->code !== ToolResult::INVALID_ARGUMENTS) {
            return [];
        }

        return preg_split('/(?<=\.)\s+(?=(?:Missing required|Unexpected parameter|Parameter \'|Input:))/', (string) $result->message);
    }

    /** The gate's own timing for the call this request just made. */
    private function lastDuration(): ?float
    {
        $entries = $this->log->entries();

        return $entries === [] ? null : end($entries)['duration_ms'] ?? null;
    }

    /** Pre-filled calls designed to fail, one per refusal path. */
    private function refusals(): array
    {
        return [
            ['label' => 'Unknown tool', 'expect' => ToolResult::TOOL_NOT_FOUND,
             'query' => ['tool' => self::OTHER, 'other' => 'delete_farm']],
            ['label' => 'Bad farm ID', 'expect' => ToolResult::INVALID_ARGUMENTS,
             'query' => ['tool' => 'farm_profile_lookup', 'args' => ['farm_id' => 'KYA12']]],
            ['label' => 'Past date', 'expect' => ToolResult::INVALID_ARGUMENTS,
             'query' => ['tool' => 'schedule_visit', 'args' => [
                 'farm_id'    => 'UG-KYA-012',
                 'visit_date' => now()->subYear()->toDateString(),
                 'purpose'    => 'Follow up unconfirmed maize leaf damage',
             ]]],
            ['label' => 'Unknown visit', 'expect' => ToolResult::RESOURCE_NOT_FOUND,
             'query' => ['tool' => 'log_followup', 'args' => [
                 'farm_id'  => 'UG-KYA-012',
                 'visit_id' => 'V-9999',
                 'notes'    => 'Leaf damage confirmed as fall armyworm.',
                 'outcome'  => 'ongoing',
             ]]],
        ];
    }

    /** The first non-null type, so ['string', 'null'] renders as a string control. */
    private static function primaryType(array $schema): ?string
    {
        foreach ((array) ($schema['type'] ?? []) as $type) {
            if ($type !== 'null') {
                return $type;
            }
        }

        return null;
    }
}
