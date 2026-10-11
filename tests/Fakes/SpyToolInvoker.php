<?php

namespace Tests\Fakes;

use App\Services\Tools\ToolInvoker;
use App\Services\Tools\ToolResult;

/**
 * The real gate, with every call's approved flag recorded.
 */
class SpyToolInvoker extends ToolInvoker
{
    /** @var list<array{name: string, args: array, approved: bool}> */
    public array $calls = [];

    public function invoke(string $name, array $args, bool $approved = false): ToolResult
    {
        $this->calls[] = compact('name', 'args', 'approved');

        return parent::invoke($name, $args, $approved);
    }
}
