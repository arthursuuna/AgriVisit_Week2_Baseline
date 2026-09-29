<?php

namespace App\Services\Checklist;

use App\Services\Llm\LlmResponse;

/** Outcome of one drafting attempt: drafted, refused, failed, or no evidence. */
final class DraftResult
{
    public const STATUS_DRAFTED     = 'drafted';
    public const STATUS_REFUSED     = 'refused';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_NO_EVIDENCE = 'no_evidence';

    private function __construct(
        public readonly string $status,
        public readonly array $items,
        public readonly string $summary,
        public readonly ?string $message,
        public readonly ?string $refusalCategory,
        public readonly ?LlmResponse $meta,
        public readonly string $promptVersion,
        public readonly string $traceId,
        public readonly int $evidenceCount = 0,
    ) {
    }

    public static function drafted(array $items, string $summary, LlmResponse $meta, string $promptVersion, string $traceId, int $evidenceCount = 0): self
    {
        return new self(self::STATUS_DRAFTED, $items, $summary, null, null, $meta, $promptVersion, $traceId, $evidenceCount);
    }

    public static function refused(string $category, string $message, string $promptVersion, string $traceId, ?LlmResponse $meta = null): self
    {
        return new self(self::STATUS_REFUSED, [], '', $message, $category, $meta, $promptVersion, $traceId);
    }

    public static function failed(string $message, string $promptVersion, string $traceId): self
    {
        return new self(self::STATUS_FAILED, [], '', $message, null, null, $promptVersion, $traceId);
    }

    /**
     * Grounded drafting found nothing to draft from: either no passage cleared
     * the relevance threshold, or the model judged the passages supplied to
     * support no items. Drafting without evidence is what prompt v2.0 forbids.
     */
    public static function noEvidence(string $message, string $promptVersion, string $traceId, int $evidenceCount = 0, ?LlmResponse $meta = null): self
    {
        return new self(self::STATUS_NO_EVIDENCE, [], '', $message, null, $meta, $promptVersion, $traceId, $evidenceCount);
    }

    public function isDrafted(): bool
    {
        return $this->status === self::STATUS_DRAFTED;
    }
}
