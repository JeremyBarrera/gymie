<?php

namespace Tests\Unit;

use App\Helpers\Helpers;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class MemberCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2025, 6, 17));

        // The generator reads existing rows, not saved state — keep model
        // events out so factories don't recurse into code generation.
        Member::flushEventListeners();

        // Override settings in-memory so we always start at "GY-1"
        Helpers::setTestSettingsOverride([
            'member' => ['prefix' => ''],
        ]);
    }

    #[Test]
    #[TestDox('A stale saved last_number is ignored — DB is the only source')]
    public function stale_saved_last_number_is_ignored(): void
    {
        Helpers::setTestSettingsOverride([
            'member' => ['prefix' => '', 'last_number' => '999'],
        ]);

        $next = Helpers::generateLastNumber(
            'member',
            Member::class,
            null,
            'code'
        );

        $this->assertSame(
            'GY-1',
            $next,
            'No manual sequence override exists: an empty span always yields GY-1'
        );
    }

    #[Test]
    #[TestDox('Step 1: Given no existing members → returns GY-1')]
    public function no_existing_members_returns_g_y1(): void
    {
        $next = Helpers::generateLastNumber(
            'member',
            Member::class,
            null,
            'code'
        );

        $this->assertSame(
            'GY-1',
            $next,
            'When there are no members at all, the next number should be GY-1'
        );
    }

    #[Test]
    #[TestDox('Step 2: Given two members in the fiscal year → returns GY-3')]
    public function two_in_range_members_returns_g_y3(): void
    {
        Member::factory()->create([
            'code' => 'GY-1',
        ]);
        Member::factory()->create([
            'code' => 'GY-2',
        ]);

        $next = Helpers::generateLastNumber(
            'member',
            Member::class,
            null,
            'code'
        );

        $this->assertSame(
            'GY-3',
            $next,
            'With two in-year members (GY-1, GY-2), the next should be GY-3'
        );
    }

    #[Test]
    #[TestDox('Step 3: Given only out-of-range members → returns GY-1')]
    public function out_of_range_members_returns_g_y1(): void
    {
        // This one is dated before the FY start, so should be ignored
        Member::factory()->create([
            'code' => 'GY-1',
            'created_at' => Carbon::create(2025, 3, 31, 23, 59, 59),
        ]);

        $next = Helpers::generateLastNumber(
            'member',
            Member::class,
            null,
            'code'
        );

        $this->assertSame(
            'GY-1',
            $next,
            'An member before the fiscal-year cutoff should not bump the counter'
        );
    }
}
