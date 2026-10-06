<?php

namespace App\Services\Tools;

use Illuminate\Support\Str;
use Throwable;

/**
 * The gate. The only thing that calls ToolContract::execute().
 *
 * Allow-list, input validation, authorization, preflight, approval, execute,
 * output validation, in that order, and every attempt is logged. Every refusal
 * is a ToolResult; no tool failure escapes as an exception.
 */
class ToolInvoker
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly SchemaValidator $validator,
        private readonly ToolPolicy $policy,
        private readonly ToolCallLog $log,
    ) {
    }

    public function invoke(string $name, array $args, bool $approved = false): ToolResult
    {
        $started = hrtime(true);
        $tool    = $this->registry->get($name);
        $result  = $this->gate($tool, $name, $args, $approved);

        $this->log->record([
            'call_id'     => (string) Str::uuid(),
            'tool'        => $name,
            'registered'  => $tool !== null,
            'write'       => $tool?->isWrite(),
            'approved'    => $approved,
            'arguments'   => $args,
            'outcome'     => $result->code ?? 'OK',
            'message'     => $result->message,
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 2),
        ]);

        return $result;
    }

    private function gate(?ToolContract $tool, string $name, array $args, bool $approved): ToolResult
    {
        if ($tool === null) {
            return ToolResult::failure(ToolResult::TOOL_NOT_FOUND,
                "No tool named '{$name}' is available.");
        }

        $errors = $this->validator->validate($tool->inputSchema(), $args);

        if ($errors !== []) {
            return ToolResult::failure(ToolResult::INVALID_ARGUMENTS, implode(' ', $errors));
        }

        if (! $this->policy->allows($tool)) {
            return ToolResult::failure(ToolResult::UNAUTHORIZED,
                "The tool '{$name}' is not enabled.");
        }

        try {
            if ($tool instanceof PreflightsArguments && ($refusal = $tool->preflight($args)) !== null) {
                return $refusal;
            }

            if ($tool->isWrite() && ! $approved) {
                return ToolResult::awaitingApproval($name, $args, $tool->approvalSummary($args));
            }

            $result = $tool->execute($args);
        } catch (Throwable $e) {
            // A tool must never take down the request.
            return ToolResult::failure(ToolResult::SERVICE_UNAVAILABLE,
                "The tool '{$name}' could not complete: {$e->getMessage()}");
        }

        if (! $result->ok) {
            return $result;
        }

        $errors = $this->validator->validate($tool->outputSchema(), $result->data);

        if ($errors !== []) {
            // The data is discarded: a wrong shape is a failure, not a partial success.
            return ToolResult::failure(ToolResult::UNEXPECTED_RESPONSE,
                "The tool '{$name}' returned data that does not match its declared output. " . implode(' ', $errors));
        }

        return $result;
    }
}
