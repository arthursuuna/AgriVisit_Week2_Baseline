<?php

namespace App\Services\Tools;

/**
 * Which registered tools may currently be used.
 *
 * Read from config on every call, so a tool can be switched off without
 * deleting it.
 */
class ToolPolicy
{
    public function allows(ToolContract $tool): bool
    {
        return in_array($tool->name(), (array) config('agrivisit.tools.enabled', []), true);
    }
}
