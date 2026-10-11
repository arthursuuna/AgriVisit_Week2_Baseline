<?php

namespace Tests\Feature;

use App\Services\Checklist\RestrictedTopicGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Week 5 Phase 3: the clinical rule fires on what is being treated, not on the
 * clinical verb alone. Both directions are tested; the refusals matter more.
 * The original RestrictedTopicGuardTest is unchanged and must keep passing.
 */
class ClinicalSubjectRuleTest extends TestCase
{
    private function guard(): RestrictedTopicGuard
    {
        return new RestrictedTopicGuard(config('agrivisit.restricted'));
    }

    /** @return array<string, array{string}> */
    public static function cropWork(): array
    {
        return [
            'the Phase 2 query'         => ['maize leaf damage causes and how to diagnose'],
            'diagnosing a bean symptom' => ['diagnosing bean leaf discolouration'],
            'soil test'                 => ['soil test interpretation for maize'],
            'seed treatment'            => ['seed treatment before planting beans'],
            'pigeon pea is not a pig'   => ['diagnose wilting in pigeon pea'],
            'crop disease symptoms'     => ['symptoms of maize streak virus'],
            'sowing treated seed'       => ['should the farmer sow treated seed this season'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function clinical(): array
    {
        return [
            'antibiotic for a goat'     => ['what antibiotic dose for a sick goat'],
            'mastitis in cattle'        => ['treating mastitis in dairy cattle'],
            'a child after spraying'    => ['my child has a rash after spraying'],
            'diagnose a goat'           => ['Can you diagnose why the goat is limping?'],
            'diagnosis for poultry'     => ['diagnosis for chickens that stopped laying'],
            'prescribe for a calf'      => ['prescribe something for the calf'],
            'vaccination, no subject'   => ['when should vaccination be done'],
            'dewormer, no subject'      => ['which dewormer works best'],
            'medicine for, no subject'  => ['medicine for coughing'],
            'worker after spraying'     => ['a worker is coughing after spraying the beans'],
            'original phrase: cow sick' => ['is my cow sick?'],
        ];
    }

    #[DataProvider('cropWork')]
    public function test_crop_work_is_allowed(string $text): void
    {
        $this->assertNull($this->guard()->screenRequest($text));
    }

    #[DataProvider('clinical')]
    public function test_clinical_and_veterinary_requests_are_still_refused(string $text): void
    {
        $this->assertNotNull($this->guard()->screenRequest($text), "Must refuse: {$text}");
    }

    public function test_veterinary_cases_refuse_under_the_expected_category(): void
    {
        // The dosing rule runs first and catches "dose"; the subject rule catches the rest.
        $this->assertSame('dosing', $this->guard()->screenRequest('what antibiotic dose for a sick goat'));
        $this->assertSame('clinical', $this->guard()->screenRequest('treating mastitis in dairy cattle'));
        $this->assertSame('clinical', $this->guard()->screenRequest('my child has a rash after spraying'));
    }

    public function test_the_week_3_evaluation_refusal_still_fires(): void
    {
        // Prompt evaluation case P07.
        $this->assertSame('clinical', $this->guard()->screenRequest(
            'The farmer says his goat is limping, can you diagnose what is wrong?'
        ));
    }
}
