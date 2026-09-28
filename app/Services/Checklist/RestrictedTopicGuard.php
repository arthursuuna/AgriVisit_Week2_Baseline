<?php

namespace App\Services\Checklist;

/**
 * Deterministic restricted-topic screening.
 *
 * The AI Boundary Matrix (Week 1, section 4) requires that what counts as
 * restricted is decided by rule, not by asking the model to behave. This class
 * holds that rule. It runs before the model is called, and again on the model's
 * output, so a refusal cannot be defeated by rephrasing the request.
 *
 * This is the seed of the Week 4 guardrail engine, deliberately kept small.
 */
class RestrictedTopicGuard
{
    /** Numeric dose patterns: "200 ml/l", "2.5 kg per hectare", "50ml per acre". */
    private const DOSE_PATTERN =
        '/\b\d+(?:\.\d+)?\s*(?:ml|l|litres?|liters?|kg|g|grams?|oz)\s*(?:\/|per\s+)\s*'
        . '(?:l|litre|liter|ha|hectare|acre|plant|tree|knapsack|tank)\b/i';

    public function __construct(private readonly array $restricted)
    {
    }

    /**
     * Screen an incoming officer request before any model call.
     *
     * @return string|null The category that fired, or null if the request is allowed.
     */
    public function screenRequest(string $text): ?string
    {
        $haystack = mb_strtolower($text);

        foreach ($this->restricted as $category => $terms) {
            foreach ($terms as $term) {
                if (str_contains($haystack, mb_strtolower($term))) {
                    return $category;
                }
            }
        }

        if (preg_match(self::DOSE_PATTERN, $text) === 1) {
            return 'dosing';
        }

        return null;
    }

    /**
     * Screen generated output. Catches a model that produced a dose without
     * being asked for one.
     */
    public function screenOutput(string $text): ?string
    {
        return preg_match(self::DOSE_PATTERN, $text) === 1 ? 'dosing' : null;
    }

    /** Approved refusal wording. The model never composes this itself. */
    public function refusalFor(string $category): string
    {
        return match ($category) {
            'dosing' => 'AgriVisit does not provide pesticide doses, application rates or '
                . 'mixing ratios. Refer the officer to the product label and a registered '
                . 'agro-input dealer or district agricultural officer.',
            'clinical' => 'AgriVisit does not diagnose animal or human health conditions or '
                . 'recommend treatment. Refer the officer to a qualified veterinary or '
                . 'medical professional.',
            default => 'This request falls outside what AgriVisit is permitted to answer. '
                . 'Refer the officer to a licensed specialist.',
        };
    }
}
