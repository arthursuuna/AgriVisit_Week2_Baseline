<?php

namespace App\Services\Embedding;

use RuntimeException;
use Throwable;

/**
 * Raised when a vector cannot be produced. Never replaced by a partial or zero
 * vector, which would silently score as irrelevant.
 *
 * Carries the HTTP status when there was one, so a caller can tell a rate limit
 * (429) apart from a permanent failure.
 */
class EmbeddingException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }
}
