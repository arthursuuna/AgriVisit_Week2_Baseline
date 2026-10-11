<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every page carries the same header nav, so each part is reachable from the others. */
class HeaderNavTest extends TestCase
{
    use RefreshDatabase;

    public static function pages(): array
    {
        return [
            'visit prep'     => ['/', 'Visit Prep Console'],
            'tool console'   => ['/tools', 'Tool Console'],
            'approval audit' => ['/tools/audit', 'Approval audit'],
            'agent'          => ['/agent', 'Agent runs'],
            'agent runs'     => ['/agent/runs', 'Agent runs'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_links_every_section_and_marks_its_own(string $uri, string $current): void
    {
        $response = $this->get($uri)->assertOk();

        $response->assertSee('<strong aria-current="page">' . $current . '</strong>', false);

        foreach (['/' => 'Visit Prep Console', '/tools' => 'Tool Console', '/tools/audit' => 'Approval audit', '/agent' => 'Agent runs'] as $path => $label) {
            if ($label !== $current) {
                $response->assertSee('href="' . url($path) . '">' . $label . '</a>', false);
            }
        }
    }
}
