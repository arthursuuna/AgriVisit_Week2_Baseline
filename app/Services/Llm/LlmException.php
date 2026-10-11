<?php

namespace App\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Raised when the model cannot be reached or returns an unusable response.
 *
 * The application treats this as a handled failure: the officer is told the
 * draft could not be produced. It never degrades into a silent empty result.
 *
 * Carries the HTTP status when there was one, and a kind, so a caller can tell
 * a rate limit or a timeout (try again later) from a broken request.
 */
class LlmException extends RuntimeException
{
    public const RATE_LIMITED = 'rate_limited';
    public const TIMEOUT      = 'timeout';
    public const UNAVAILABLE  = 'unavailable';
    public const HTTP_ERROR   = 'http_error';
    public const OTHER        = 'other';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?int $status = null,
        public readonly string $kind = self::OTHER,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** A non-2xx response from the model API. */
    public static function fromResponse(Response $response): self
    {
        $status = $response->status();

        $kind = match (true) {
            $status === 429                          => self::RATE_LIMITED,
            in_array($status, [408, 504], true)      => self::TIMEOUT,
            in_array($status, [500, 502, 503], true) => self::UNAVAILABLE,
            default                                  => self::HTTP_ERROR,
        };

        return new self("Model returned HTTP {$status}: " . $response->body(), 0, null, $status, $kind);
    }

    /** The request never got a response: refused, unresolved or timed out. */
    public static function fromTransport(Throwable $e, int $timeoutSeconds): self
    {
        $timedOut = $e instanceof ConnectionException
            && (str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false);

        return $timedOut
            ? new self("Model request timed out after {$timeoutSeconds} s: " . $e->getMessage(), 0, $e, null, self::TIMEOUT)
            : new self('Model request failed: ' . $e->getMessage(), 0, $e, null, self::UNAVAILABLE);
    }

    public function isRateLimited(): bool
    {
        return $this->kind === self::RATE_LIMITED;
    }

    /** One sentence for the officer. The raw message stays in the logs. */
    public function reason(): string
    {
        return match ($this->kind) {
            self::RATE_LIMITED => 'The model is rate limited, try again later. (HTTP 429: the request or daily quota for this API key has been used up.)',
            self::TIMEOUT      => 'The model did not respond in time, try again later.',
            self::UNAVAILABLE  => $this->status
                ? "The model service is busy or unavailable, try again later. (HTTP {$this->status})"
                : 'The model service could not be reached, try again later.',
            self::HTTP_ERROR   => "The model refused the request (HTTP {$this->status}).",
            default            => 'The model returned an unusable response: ' . $this->getMessage(),
        };
    }
}
