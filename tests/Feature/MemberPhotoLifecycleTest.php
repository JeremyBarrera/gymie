<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MemberPhotoLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
    }

    private function authorizedUser(string $permission): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate($permission, 'web');

        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_replacing_a_member_photo_via_api_deletes_the_previous_file(): void
    {
        $this->authorizedUser('Update:Member');

        Storage::fake('public');

        $member = Member::factory()->create(['photo' => 'images/member-old.jpg']);
        Storage::disk('public')->put('images/member-old.jpg', 'old-bytes');

        $this->putJson("/api/v1/members/{$member->id}", [
            'name' => $member->name,
            'contact' => $member->contact,
            'government_id' => $member->government_id,
            'photo' => UploadedFile::fake()->image('new-photo.jpg'),
        ])->assertStatus(200);

        Storage::disk('public')->assertMissing('images/member-old.jpg');
        Storage::disk('public')->assertExists($member->refresh()->photo);
    }

    public function test_force_deleting_a_member_removes_the_photo_from_disk(): void
    {
        $this->authorizedUser('ForceDeleteAny:Member');

        Storage::fake('public');

        $member = Member::factory()->create(['photo' => 'images/member-gone.jpg']);
        Storage::disk('public')->put('images/member-gone.jpg', 'photo-bytes');

        DB::table('members')->where('id', $member->id)->update(['deleted_at' => now()]);

        $this->deleteJson("/api/v1/members/{$member->id}/force")->assertStatus(204);

        $this->assertSame(0, DB::table('members')->where('id', $member->id)->count());
        Storage::disk('public')->assertMissing('images/member-gone.jpg');
    }

    public function test_soft_deleting_a_member_keeps_the_photo_for_restore(): void
    {
        $this->authorizedUser('Delete:Member');

        Storage::fake('public');

        $member = Member::factory()->create(['photo' => 'images/member-soft.jpg']);
        Storage::disk('public')->put('images/member-soft.jpg', 'photo-bytes');

        $member->delete();

        Storage::disk('public')->assertExists('images/member-soft.jpg');
    }
}
