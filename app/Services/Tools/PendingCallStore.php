<?php

namespace App\Services\Tools;

use Illuminate\Cache\Repository;
use Illuminate\Support\Str;

/**
 * Held write calls, server-side. The browser only ever holds the token.
 *
 * An entry is kept well past its expiry so a late submission can still be
 * recognised and recorded as expired. consume() replaces the entry with a
 * tombstone under a lock, which is what makes a token single-use: a double
 * submission finds the tombstone, never the call.
 */
class PendingCallStore
{
    private const PREFIX = 'pending-call:';

    /** How long an entry or tombstone is remembered, long after it expires. */
    private const RETAIN_SECONDS = 86400;

    public function __construct(
        private readonly Repository $cache,
        private readonly int $expiryMinutes = 10,
    ) {
    }

    /** @return string the token the browser posts back */
    public function put(array $pending, string $origin, ?string $sessionId): string
    {
        $token = (string) Str::uuid();

        $this->cache->put(self::PREFIX . $token, [
            'pending'     => $pending,
            'origin'      => $origin,
            'session_id'  => $sessionId,
            'proposed_at' => now()->getTimestamp(),
        ], self::RETAIN_SECONDS);

        return $token;
    }

    /** A live entry, or null when unknown, decided or expired. Does not consume. */
    public function get(string $token): ?array
    {
        $entry = $this->read($token);

        return $entry === null || $entry['expired'] ? null : $entry;
    }

    /**
     * Returns the entry and invalidates the token, at most once per token.
     * An expired entry is still returned, flagged 'expired', so the caller can
     * record it; it can never be approved.
     */
    public function consume(string $token): ?array
    {
        return $this->cache->lock(self::PREFIX . 'lock:' . $token, 10)->block(5, function () use ($token) {
            $entry = $this->read($token);

            if ($entry !== null) {
                $this->cache->put(self::PREFIX . $token, ['consumed_at' => now()->getTimestamp()], self::RETAIN_SECONDS);
            }

            return $entry;
        });
    }

    /** True when the token was valid once and has already been decided. */
    public function wasConsumed(string $token): bool
    {
        return isset($this->raw($token)['consumed_at']);
    }

    public function expiryMinutes(): int
    {
        return $this->expiryMinutes;
    }

    private function read(string $token): ?array
    {
        $entry = $this->raw($token);

        if ($entry === null || isset($entry['consumed_at'])) {
            return null;
        }

        $expiresAt = $entry['proposed_at'] + $this->expiryMinutes * 60;

        return $entry + [
            'token'      => $token,
            'expires_at' => $expiresAt,
            'expired'    => now()->getTimestamp() > $expiresAt,
        ];
    }

    private function raw(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $entry = $this->cache->get(self::PREFIX . $token);

        return is_array($entry) ? $entry : null;
    }
}
