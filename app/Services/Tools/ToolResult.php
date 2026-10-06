<?php

namespace App\Services\Tools;

/**
 * The outcome of one tool invocation.
 *
 * Always a value, never an exception. The model, the orchestrator and the
 * evidence table all read the same codes.
 */
final class ToolResult
{
    public const TOOL_NOT_FOUND      = 'TOOL_NOT_FOUND';
    public const INVALID_ARGUMENTS   = 'INVALID_ARGUMENTS';
    public const UNAUTHORIZED        = 'UNAUTHORIZED';
    public const APPROVAL_REQUIRED   = 'APPROVAL_REQUIRED';
    public const RESOURCE_NOT_FOUND  = 'RESOURCE_NOT_FOUND';
    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    public const UNEXPECTED_RESPONSE = 'UNEXPECTED_RESPONSE';

    private function __construct(
        public readonly bool $ok,
        public readonly array $data,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly ?array $pending,
    ) {
    }

    public static function success(array $data): self
    {
        return new self(true, $data, null, null, null);
    }

    public static function failure(string $code, string $message): self
    {
        return new self(false, [], $code, $message, null);
    }

    /**
     * A write tool that would succeed, held for officer approval.
     * $pending carries the tool name and arguments so the action can be
     * replayed verbatim once approved.
     */
    public static function awaitingApproval(string $tool, array $args, string $summary): self
    {
        return new self(false, [], self::APPROVAL_REQUIRED, $summary,
            ['tool' => $tool, 'arguments' => $args]);
    }

    public function toArray(): array
    {
        return [
            'ok'      => $this->ok,
            'data'    => $this->data,
            'code'    => $this->code,
            'message' => $this->message,
        ];
    }
}
