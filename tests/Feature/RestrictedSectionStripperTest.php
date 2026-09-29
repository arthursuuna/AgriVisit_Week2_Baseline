<?php

namespace Tests\Feature;

use App\Services\Corpus\RestrictedSectionStripper;
use Tests\TestCase;

class RestrictedSectionStripperTest extends TestCase
{
    private function stripper(): RestrictedSectionStripper
    {
        return new RestrictedSectionStripper(config('agrivisit.corpus.excluded_headings'));
    }

    private function sample(): string
    {
        // Multi-level section numbers: single-level "1." lines are list steps
        // and do not end an excluded section.
        return "MAIZE PRODUCTION GUIDE\n\n"
            . "2.1 Land Preparation\n"
            . "Clear the plot and remove crop residue before the rains begin.\n\n"
            . "2.2 Chemical Control\n"
            . "Apply 200 ml/l of the product to the knapsack sprayer.\n"
            . "Use protective clothing during all spraying operations.\n\n"
            . "2.3 Mulching Practice\n"
            . "Mulch conserves soil moisture and suppresses weed growth.\n"
            . "Apply 2.5 l per hectare at planting.\n"
            . "The farmer should record the planting date in the book.\n";
    }

    public function test_it_removes_an_excluded_section_including_its_body(): void
    {
        $result = $this->stripper()->strip($this->sample());

        $this->assertStringNotContainsString('protective clothing', $result['text']);
        $this->assertStringNotContainsString('200 ml/l', $result['text']);
        $this->assertSame(1, $result['sections_removed']);
        $this->assertContains('2.2 Chemical Control', $result['removed_headings']);
    }

    public function test_it_removes_a_stray_rate_line_outside_an_excluded_section(): void
    {
        $result = $this->stripper()->strip($this->sample());

        $this->assertStringNotContainsString('2.5 l per hectare', $result['text']);
    }

    public function test_it_keeps_the_line_next_to_a_removed_rate(): void
    {
        $result = $this->stripper()->strip($this->sample());

        $this->assertStringContainsString('record the planting date', $result['text']);
    }

    public function test_it_keeps_legitimate_sections(): void
    {
        $result = $this->stripper()->strip($this->sample());

        $this->assertStringContainsString('Clear the plot', $result['text']);
        $this->assertStringContainsString('Mulch conserves', $result['text']);
    }

    public function test_it_recognises_heading_shapes(): void
    {
        $s = $this->stripper();

        $this->assertTrue($s->looksLikeHeading('3.2 Land Preparation'));
        $this->assertTrue($s->looksLikeHeading('CHEMICAL CONTROL'));
        $this->assertTrue($s->looksLikeHeading('Mulching Practice'));
        $this->assertFalse($s->looksLikeHeading('Clear the plot and remove crop residue before the rains.'));
        $this->assertFalse($s->looksLikeHeading(''));
    }

    public function test_numbered_steps_do_not_end_an_excluded_section(): void
    {
        $text = "4.3.1 Reading the Product label\n"
            . "The label gives the active ingredient and first aid.\n"
            . "4.3.2 Determining how much pesticide to use\n"
            . "Steps of calibration of a knapsack sprayer\n"
            . "1. Measure out an area of 100 square metres\n"
            . "2. Fill the knapsack with known volume of water e.g 15 litres of water\n"
            . "8. Calculate the volume of water needed to spray an acre\n"
            . "0.15 x 1000ml = 150mls\n"
            . "4.3.3 Mixing Agro-Chemicals\n"
            . "Always mix and fill outdoors.\n";

        $result = $this->stripper()->strip($text);

        $this->assertStringNotContainsString('Measure out an area', $result['text']);
        $this->assertStringNotContainsString('Fill the knapsack', $result['text']);
        $this->assertStringNotContainsString('Calculate the volume', $result['text']);
        $this->assertStringNotContainsString('150mls', $result['text']);
        $this->assertStringContainsString('active ingredient', $result['text']);
        $this->assertStringContainsString('4.3.3 Mixing Agro-Chemicals', $result['text']);
        $this->assertStringContainsString('mix and fill outdoors', $result['text']);
        $this->assertSame(['4.3.2 Determining how much pesticide to use'], $result['removed_headings']);
    }

    public function test_a_seed_rate_is_kept(): void
    {
        $result = $this->stripper()->strip("2.7 Spacing and Seed rate\nRecommended seed rate = 30 kg per acre\n");

        $this->assertStringContainsString('Recommended seed rate = 30 kg per acre', $result['text']);
    }

    public function test_a_volume_rate_without_a_space_is_removed(): void
    {
        $line = 'Using Round up at a rate of 1.5lts/acre, calculate the amount of chemical';

        $result = $this->stripper()->strip("3.2 Weed Management\n{$line}\nHand weeding is effective.\n");

        $this->assertStringNotContainsString('1.5lts/acre', $result['text']);
        $this->assertStringContainsString('Hand weeding is effective.', $result['text']);
    }

    public function test_a_dilution_into_a_knapsack_is_removed(): void
    {
        $line = 'Farmer can also calculate needed mls per litre of water = 150ml/20 = 7.5ml';

        $result = $this->stripper()->strip("3.2 Weed Management\n{$line}\nHand weeding is effective.\n");

        $this->assertStringNotContainsString('7.5ml', $result['text']);
        $this->assertStringContainsString('Hand weeding is effective.', $result['text']);
    }

    public function test_a_chunk_with_a_named_herbicide_and_a_volume_is_restricted(): void
    {
        $this->assertTrue($this->stripper()->chunkIsRestricted('Roundup is 1.5L (=1500ml) per Acre'));
    }

    public function test_a_chunk_with_a_knapsack_and_a_volume_is_restricted(): void
    {
        $this->assertTrue($this->stripper()->chunkIsRestricted(
            'Fill the knapsack for the calculation. 0.15lts x 1000ml = 150mls'
        ));
    }

    public function test_chemical_context_without_a_quantity_is_not_restricted(): void
    {
        $this->assertFalse($this->stripper()->chunkIsRestricted(
            'Always read the label carefully and understand the instruction'
        ));
    }

    public function test_a_water_volume_without_chemical_context_is_not_restricted(): void
    {
        $this->assertFalse($this->stripper()->chunkIsRestricted(
            'Fill the container with 15 litres of water before planting'
        ));
    }

    public function test_a_seed_rate_chunk_is_not_restricted(): void
    {
        $this->assertFalse($this->stripper()->chunkIsRestricted('Recommended seed rate is 30 kg per acre'));
    }

    public function test_a_weight_mixed_into_litres_is_restricted_without_a_chemical_word(): void
    {
        $this->assertTrue($this->stripper()->chunkIsRestricted('Mancozeb at 50gms in 20 litre of water'));
    }

    public function test_a_volume_mixed_into_litres_is_restricted_without_a_chemical_word(): void
    {
        $this->assertTrue($this->stripper()->chunkIsRestricted('Cypermethrin at a rate of 70ml in 20 litres of water'));
    }

    public function test_a_plural_mls_per_litre_is_restricted(): void
    {
        $this->assertTrue($this->stripper()->chunkIsRestricted('apply at a rate of 1.5mls per litre of water'));
    }

    public function test_a_weight_per_litre_is_restricted(): void
    {
        $this->assertTrue($this->stripper()->chunkIsRestricted('500gms/litre of Carbendazim'));
    }

    public function test_a_plain_water_volume_is_not_restricted(): void
    {
        $this->assertFalse($this->stripper()->chunkIsRestricted('Water the seedlings with 15 litres of water each morning'));
    }

    public function test_only_the_sentence_with_a_mix_ratio_is_removed(): void
    {
        $result = $this->stripper()->stripMixRatioSentences(
            'Check for pests weekly. Apply Mancozeb at 50gms in 20 litres of water. Keep the cover airtight.'
        );

        $this->assertStringContainsString('Check for pests weekly.', $result['text']);
        $this->assertStringContainsString('Keep the cover airtight.', $result['text']);
        $this->assertStringNotContainsString('Mancozeb', $result['text']);
        $this->assertSame(1, $result['sentences_removed']);
    }

    public function test_only_the_bullet_item_with_a_mix_ratio_is_removed(): void
    {
        $result = $this->stripper()->stripMixRatioSentences(
            '➢ Water twice daily ➢ Apply Cypermethrin 70ml in 20 litres ➢ Open cages for two hours'
        );

        $this->assertStringContainsString('Water twice daily', $result['text']);
        $this->assertStringContainsString('Open cages for two hours', $result['text']);
        $this->assertStringNotContainsString('Cypermethrin', $result['text']);
        $this->assertSame(1, $result['sentences_removed']);
    }

    public function test_a_ratio_with_the_product_named_before_the_volume_is_removed(): void
    {
        $result = $this->stripper()->stripMixRatioSentences(
            'Water the rooting media first. Dip the cuttings in a solution made from 50gms of copper oxychloride in 20 litres of water. Place them firmly.'
        );

        $this->assertStringNotContainsString('copper oxychloride', $result['text']);
        $this->assertStringContainsString('Place them firmly.', $result['text']);
        $this->assertSame(1, $result['sentences_removed']);
    }

    public function test_a_passage_without_a_mix_ratio_is_unchanged(): void
    {
        $text = "4.5.5 Management of rooted cuttings\nMaintain an airtight cover. Water once every week.";

        $result = $this->stripper()->stripMixRatioSentences($text);

        $this->assertSame($text, $result['text']);
        $this->assertSame(0, $result['sentences_removed']);
    }

    public function test_the_roundup_calculation_survives_sentences_but_is_caught_by_the_chunk_filter(): void
    {
        // DOC-002: the amounts are spread across sentences, so no one sentence is a ratio.
        $roundup = 'Look at the application rate on the product label. E.G.: Roundup is 1.5L (=1500ml) per Acre '
            . 'and an acre = 4,000 sq. metres. If 100sq.m took 5lts then 1 Sq.m will take 5/100 lts. '
            . 'Therefore, An acre = (4000sq.m x 5lts)/100sqm = 200lts of 20lt capacity as 200lts of water is '
            . 'needed to dilute 1.5 Liters of Round up. Calculation: • 200 lts of water needs 1.5 lts of chemical, '
            . '• 1 liter of water 1.5/200 lts of chemical • Therefore, a knapsack of 20 lts of water (20x1.5) '
            . '/200= 0.15lts of Roundup';

        $result = $this->stripper()->stripMixRatioSentences($roundup);

        $this->assertSame(0, $result['sentences_removed']);
        $this->assertTrue($this->stripper()->chunkIsRestricted($result['text']));
    }

    public function test_the_line_level_rate_pattern_catches_plural_mls(): void
    {
        $result = $this->stripper()->strip("4.5.5 Management of rooted cuttings\napply at a rate of 1.5mls per litre of water\nWater once every week.\n");

        $this->assertStringNotContainsString('1.5mls', $result['text']);
        $this->assertStringContainsString('Water once every week.', $result['text']);
    }
}
