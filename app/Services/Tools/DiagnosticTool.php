<?php

namespace App\Services\Tools;

/**
 * Marks test instrumentation: a tool that exists to exercise a failure path.
 *
 * Registered and allowed only when agrivisit.tools.probes_enabled is true
 * (TOOLS_PROBES_ENABLED, default false), and never declared to the model even
 * then: the assistant must never be offered a tool that exists to break.
 */
interface DiagnosticTool
{
}
