<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Support\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GovernmentIdEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_government_id_is_encrypted_at_rest(): void
    {
        $member = Member::factory()->create(['government_id' => 'ID-PLAINTEXT-1']);

        $raw = DB::table('members')->where('id', $member->id)->value('government_id');

        $this->assertNotSame('ID-PLAINTEXT-1', $raw);
        $this->assertStringStartsWith('eyJ', (string) $raw);
        $this->assertSame('ID-PLAINTEXT-1', $member->refresh()->government_id);
    }

    public function test_blind_index_hash_is_maintained_on_save(): void
    {
        $member = Member::factory()->create(['government_id' => 'ID-HASH-1']);

        $this->assertSame(
            BlindIndex::compute('ID-HASH-1'),
            DB::table('members')->where('id', $member->id)->value('government_id_hash'),
        );

        $member->update(['government_id' => 'ID-HASH-2']);

        $this->assertSame(
            BlindIndex::compute('ID-HASH-2'),
            DB::table('members')->where('id', $member->id)->value('government_id_hash'),
        );
    }

    public function test_exact_lookup_scope_finds_member_by_plaintext_id(): void
    {
        Member::factory()->create(['government_id' => 'ID-LOOKUP-1']);
        Member::factory()->create(['government_id' => 'ID-LOOKUP-2']);

        $found = Member::query()->whereGovernmentId('ID-LOOKUP-1')->get();

        $this->assertCount(1, $found);
        $this->assertSame('ID-LOOKUP-1', $found->first()->government_id);
        $this->assertSame(0, Member::query()->whereGovernmentId('ID-NOTHING')->count());
    }

    public function test_duplicate_detection_still_matches_after_encryption(): void
    {
        Member::factory()->create([
            'name' => 'Jane Doe',
            'contact' => '5551234567',
            'government_id' => 'ID-DUP-1',
        ]);

        $duplicate = Member::findDuplicateByIdentifiers([
            'name' => 'Jane Doe',
            'contact' => ['+15551234567', '5551234567'],
            'government_id' => 'ID-DUP-1',
        ]);

        $this->assertNotNull($duplicate);

        $different = Member::findDuplicateByIdentifiers([
            'name' => 'Jane Doe',
            'contact' => ['5551234567'],
            'government_id' => 'ID-DIFFERENT',
        ]);

        $this->assertNull($different);
    }

    public function test_identifier_search_matches_full_government_id_only(): void
    {
        Member::factory()->create(['government_id' => 'ID-SEARCH-9999', 'name' => 'Zed Person']);

        $exact = Member::searchByIdentifier('ID-SEARCH-9999');
        $partial = Member::searchByIdentifier('ID-SEARCH');

        $this->assertGreaterThan(0, $exact->where('government_id', 'ID-SEARCH-9999')->count());
        $this->assertSame(0, $partial->count());
    }
}
