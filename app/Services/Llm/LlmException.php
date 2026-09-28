<?php

namespace App\Services\Llm;

use RuntimeException;

/**
 * Raised when the model cannot be reached or returns an unusable response.
 *
 * The application treats this as a handled failure: the officer is told the
 * draft could not be produced. It never degrades into a silent empty result.
 */
class LlmException extends RuntimeException
{
}
