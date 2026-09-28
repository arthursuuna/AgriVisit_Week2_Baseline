<?php

namespace App\Services\Checklist;

use RuntimeException;

/** Raised when model output does not satisfy the prompt's output contract. */
class ChecklistFormatException extends RuntimeException
{
}
