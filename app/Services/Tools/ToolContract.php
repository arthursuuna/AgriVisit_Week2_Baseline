<?php

namespace App\Services\Tools;

interface ToolContract
{
    /** snake_case, stable. This is the name the model calls. */
    public function name(): string;

    /** One sentence the model reads to decide whether this tool applies. */
    public function description(): string;

    /** JSON-Schema-style: ['type' => 'object', 'properties' => [...], 'required' => [...]] */
    public function inputSchema(): array;

    /** Same shape. Used to validate what the tool returns. */
    public function outputSchema(): array;

    /** True when the tool changes state and therefore needs officer approval. */
    public function isWrite(): bool;

    /** A one-line description of what approving this call would do. */
    public function approvalSummary(array $args): string;

    /** Arguments are already validated when this is called. */
    public function execute(array $args): ToolResult;
}
