<?php

namespace Tests\Feature;

use App\Services\Checklist\RestrictedTopicGuard;
use Tests\TestCase;

/**
 * The guard is deterministic, so it is unit-testable without calling the model.
 * These tests protect the AI Boundary Matrix commitment that restricted topics
 * are decided by rule, not by the model's cooperation.
 */
class RestrictedTopicGuardTest extends TestCase
{
    private function guard(): RestrictedTopicGuard
    {
        return new RestrictedTopicGuard(config('agrivisit.restricted'));
    }

    public function test_it_blocks_an_explicit_dose_request(): void
    {
        $this->assertSame('dosing', $this->guard()->screenRequest(
            'What dose of pesticide should the farmer apply?'
        ));
    }

    public function test_it_blocks_a_numeric_rate_without_the_word_dose(): void
    {
        $this->assertSame('dosing', $this->guard()->screenRequest(
            'Farmer asks whether 40 ml/l is right for the knapsack.'
        ));
    }

    public function test_it_blocks_a_veterinary_diagnosis_request(): void
    {
        $this->assertSame('clinical', $this->guard()->screenRequest(
            'Can you diagnose why the goat is limping?'
        ));
    }

    public function test_it_allows_an_ordinary_observation(): void
    {
        $this->assertNull($this->guard()->screenRequest(
            'Farmer reported leaf damage on the maize plot last month.'
        ));
    }

    public function test_it_catches_a_dose_that_appears_in_model_output(): void
    {
        $this->assertSame('dosing', $this->guard()->screenOutput(
            'Apply 2.5 kg per hectare before flowering.'
        ));
    }

    public function test_refusal_wording_is_approved_not_generated(): void
    {
        $this->assertStringContainsString('does not provide pesticide doses', $this->guard()->refusalFor('dosing'));
        $this->assertStringContainsString('does not diagnose', $this->guard()->refusalFor('clinical'));
    }
}
