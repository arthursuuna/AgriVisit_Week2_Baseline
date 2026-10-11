<?php

namespace App\Services\Tools;

/**
 * Which registered tools may currently be used.
 *
 * Read from config on every call, so a tool can be switched off without
 * deleting it. Diagnostic probes answer to their own switch, not the
 * enabled list.
 */
class ToolPolicy
{
    public function allows(ToolContract $tool): bool
    {
        if ($tool instanceof DiagnosticTool) {
            return (bool) config('agrivisit.tools.probes_enabled', false);
        }

        return in_array($tool->name(), (array) config('agrivisit.tools.enabled', []), true);
    }
}
