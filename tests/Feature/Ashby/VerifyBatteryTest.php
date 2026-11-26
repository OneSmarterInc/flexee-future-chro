<?php

namespace Tests\Feature\Ashby;

use App\Ashby\Verify\Battery;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyBatteryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_check_passes_and_the_throwaway_class_is_rolled_back(): void
    {
        $checks = app(Battery::class)->run(true);
        $failed = array_values(array_filter($checks, fn (array $c): bool => ! $c['pass']));
        $this->assertSame([], $failed);
        $this->assertGreaterThan(15, count($checks));
        $this->assertContains('The pipeline (rolled back)', array_column($checks, 'group'));
        $this->assertSame(0, Section::query()->count());
    }

    public function test_the_command_prints_expected_beside_actual(): void
    {
        $this->artisan('ashby:verify')->expectsOutputToContain('All ')->assertSuccessful();
    }
}
