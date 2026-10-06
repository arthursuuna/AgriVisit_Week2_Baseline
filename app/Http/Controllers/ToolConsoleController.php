<?php

namespace App\Http\Controllers;

use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolInvoker;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolResult;
use App\Services\Tools\ToolSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Browser front end for the Week 4 tool layer.
 *
 * Drives ToolInvoker exactly as tinker does: no model, and no validation of
 * its own. Whatever the officer types reaches the invoker, so the page shows
 * the real refusals. Pending write calls are held in the session and replayed
 * verbatim on approval, never rebuilt from the form.
 */
class ToolConsoleController extends Controller
{
    /** Select value meaning "use the free-text tool name instead". */
    public const OTHER = '__other';

    private const PENDING_KEY = 'tool_console.pending';

    private const ASK_PENDING_KEY = 'tool_console.ask_pending';

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolInvoker $invoker,
        private readonly ToolCallLog $log,
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
        $result = $this->invoker->invoke($name, $args);
        $token  = null;

        if ($result->code === ToolResult::APPROVAL_REQUIRED && $result->pending !== null) {
            $token = (string) Str::uuid();
            $request->session()->put(self::PENDING_KEY . '.' . $token, $result->pending);
        }

        return $this->page($selected, $other, $posted, [
            'tool'     => $name,
            'args'     => $args,
            'result'   => $result,
            'duration' => $this->lastDuration(),
            'token'    => $token,
        ]);
    }

    public function approve(Request $request): View
    {
        $token   = (string) $request->input('token', '');
        $pending = $request->session()->pull(self::PENDING_KEY . '.' . $token);

        if ($pending === null) {
            return $this->page(self::OTHER, '', [], [
                'notice' => 'That approval has expired or was already used. Nothing was written.',
            ]);
        }

        $tool = $pending['tool'];

        if ($request->input('decision') === 'cancel') {
            return $this->page($tool, '', $pending['arguments'], [
                'notice' => "Cancelled. The {$tool} call was discarded and nothing was written.",
            ]);
        }

        // The exact tool and arguments the invoker returned, replayed verbatim.
        $result = $this->invoker->invoke($tool, $pending['arguments'], true);

        return $this->page($tool, '', $pending['arguments'], [
            'tool'     => $tool,
            'args'     => $pending['arguments'],
            'result'   => $result,
            'duration' => $this->lastDuration(),
            'approved' => true,
        ]);
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
        $token  = null;

        if ($result->pending !== null) {
            $token = (string) Str::uuid();
            $request->session()->put(self::ASK_PENDING_KEY . '.' . $token, [
                'request' => $question,
                'call'    => $result->pending,
            ]);
        }

        return $this->askPage(['question' => $question, 'result' => $result, 'token' => $token]);
    }

    public function askApprove(Request $request, ToolSession $session): View
    {
        $held = $request->session()->pull(self::ASK_PENDING_KEY . '.' . $request->input('token', ''));

        if ($held === null) {
            return $this->askPage(['question' => '',
                'notice' => 'That approval has expired or was already used. Nothing was written.']);
        }

        if ($request->input('decision') === 'cancel') {
            return $this->askPage(['question' => $held['request'],
                'notice' => "Cancelled. The {$held['call']['tool']} call was discarded and nothing was written."]);
        }

        // The held call, exactly as the gate returned it. Approval is this click, never the model.
        $result = $session->handle($held['request'], $held['call']);

        return $this->askPage(['question' => $held['request'], 'result' => $result, 'approved' => true]);
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
