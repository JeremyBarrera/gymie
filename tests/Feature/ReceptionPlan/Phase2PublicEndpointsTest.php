<?php

use App\Enums\Status;
use App\Events\QueueEntryCreated;
use App\Helpers\Helpers;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\Plan;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('renders the check-in scan page for a valid checkin token', function (): void {
    $location = Location::factory()->create(['theme_color' => 'indigo']);
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $this->get(route('checkin.scan', ['token' => $token->token]))
        ->assertOk()
        ->assertSee('checkin')
        ->assertSee('indigo')
        ->assertSee('id="dial-value"', false)
        ->assertSee('class="dial-code"', false);
});

it('renders the sign-up scan page for a valid signup token', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->signup()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $this->get(route('signup.scan', ['token' => $token->token]))
        ->assertOk()
        ->assertSee('signup')
        ->assertSee('id="dial-contact"', false)
        ->assertSee('id="dial-emergency_contact"', false);
});

it('returns 404 when the token does not exist or kind mismatches', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->signup()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $this->get(route('checkin.scan', ['token' => $token->token]))->assertNotFound();
    $this->get(route('checkin.scan', ['token' => 'does-not-exist']))->assertNotFound();
});

it('shows a friendly invalid-token page when the QR code was deleted', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);
    $oldToken = $token->token;
    $token->delete();

    $this->get(route('checkin.scan', ['token' => $oldToken]))
        ->assertNotFound()
        ->assertSee(__('app.scan.invalid_heading'))
        ->assertSee(__('app.scan.invalid_contact'));
});

it('localizes the invalid-token page to the phone language', function (): void {
    $this->get(route('checkin.scan', ['token' => 'does-not-exist']), [
        'Accept-Language' => 'fr',
    ])
        ->assertNotFound()
        ->assertSee('Ce code QR n’est plus valide', false)
        ->assertSee('Veuillez contacter un membre du personnel', false);
});

it('renders the invalid-token page right-to-left for arabic phones', function (): void {
    $this->get(route('checkin.scan', ['token' => 'does-not-exist']), [
        'Accept-Language' => 'ar',
    ])
        ->assertNotFound()
        ->assertSee('dir="rtl"', false)
        ->assertSee('لم يعد رمز QR هذا صالحًا');
});

it('localizes the check-in scan page via the locale query parameter', function (): void {
    $location = Location::factory()->create(['theme_color' => 'indigo']);
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $this->get(route('checkin.scan', ['token' => $token->token, 'locale' => 'fr']))
        ->assertOk()
        ->assertSee('Enregistrement du membre', false)
        ->assertSee('Numéro de téléphone', false)
        ->assertSee('Enregistrer', false)
        ->assertSee('dir="ltr"', false);

    $this->get(route('checkin.scan', ['token' => $token->token, 'locale' => 'ar']))
        ->assertOk()
        ->assertSee('تسجيل دخول العضو')
        ->assertSee('dir="rtl"', false);
});

it('localizes the waiting page via the locale query parameter', function (): void {
    $location = Location::factory()->create(['theme_color' => 'rose']);
    $queueEntry = QueueEntry::create([
        'uuid' => 'queue-test-uuid-locale',
        'location_id' => $location->id,
        'kind' => 'checkin',
        'payload' => ['member_id' => 1],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->get(route('checkin.waiting', ['uuid' => $queueEntry->uuid, 'locale' => 'fr']))
        ->assertOk()
        ->assertSee('Un membre du personnel sera avec vous sous peu', false);

    $this->get(route('checkin.waiting', ['uuid' => $queueEntry->uuid, 'locale' => 'fa']))
        ->assertOk()
        ->assertSee('به‌زودی یکی از کارکنان به شما خواهد رسید')
        ->assertSee('dir="rtl"', false);
});

it('submits a checkin for an active member and creates a queue entry', function (): void {
    Event::fake([QueueEntryCreated::class]);

    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $service = Service::factory()->create();
    $plan = Plan::factory()->create([
        'service_id' => $service->id,
        'track_uses' => false,
        'status' => Status::Active,
    ]);
    $member = Member::factory()->create([
        'contact' => '5551234567',
        'status' => Status::Active,
    ]);
    Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => Carbon::today()->subDays(5),
        'end_date' => Carbon::today()->addDays(25),
    ]);

    $response = $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'identifier_type' => 'contact',
        'value' => '5551234567',
        'token' => $token->token,
    ]);

    $response->assertOk()
        ->assertJson(['match' => true])
        ->assertJsonPath('queue_entry_uuid', fn ($uuid) => QueueEntry::where('uuid', $uuid)->exists());

    Event::assertDispatched(QueueEntryCreated::class);
});

it('returns match false when the identifier matches no active member', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'identifier_type' => 'contact',
        'value' => '9999999999',
        'token' => $token->token,
    ])
        ->assertOk()
        ->assertJson(['match' => false]);
});

it('checks in a member by government id (unique identifier)', function (): void {
    Event::fake([QueueEntryCreated::class]);

    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $service = Service::factory()->create();
    $plan = Plan::factory()->create([
        'service_id' => $service->id,
        'track_uses' => false,
        'status' => Status::Active,
    ]);
    $member = Member::factory()->create([
        'government_id' => 'GOV-12345',
        'status' => Status::Active,
    ]);
    Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => Carbon::today()->subDays(5),
        'end_date' => Carbon::today()->addDays(25),
    ]);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'identifier_type' => 'government_id',
        'value' => 'GOV-12345',
        'token' => $token->token,
    ])
        ->assertOk()
        ->assertJson(['match' => true])
        ->assertJsonPath('member.id', $member->id);
});

it('reports a localized reason when the matching member has no eligible subscription', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    Member::factory()->create([
        'government_id' => 'GOV-99999',
        'status' => Status::Active,
    ]);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'identifier_type' => 'government_id',
        'value' => 'GOV-99999',
        'token' => $token->token,
    ])
        ->assertOk()
        ->assertJson([
            'match' => false,
            'message' => __('app.reception.check_in_not_eligible'),
        ]);
});

it('reports a localized reason when the signup matches the name, phone, and ID', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->signup()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    Member::factory()->create([
        'name' => 'Test Member',
        'contact' => '5550001111',
        'government_id' => 'GOV-12345',
        'email' => 'test@example.com',
        'status' => Status::Active,
    ]);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'signup',
        'name' => 'Test Member',
        'contact' => '5550001111',
        'government_id' => 'GOV-12345',
        'gender' => 'male',
        'dob' => '1995-06-15',
        'email' => 'test@example.com',
        'goal' => 'fitness',
        'token' => $token->token,
    ])
        ->assertOk()
        ->assertJson([
            'match' => false,
            'message' => __('app.scan.member_already_exists'),
        ]);
});

it('allows a signup when only the government id matches an existing member', function (): void {
    Event::fake([QueueEntryCreated::class]);

    $location = Location::factory()->create();
    $token = LocationToken::factory()->signup()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    Member::factory()->create([
        'name' => 'Other Person',
        'contact' => '7770001111',
        'government_id' => 'GOV-12345',
        'email' => 'other@example.com',
        'status' => Status::Active,
    ]);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'signup',
        'name' => 'Test Member',
        'contact' => '5550001111',
        'government_id' => 'GOV-12345',
        'gender' => 'male',
        'dob' => '1995-06-15',
        'email' => 'test@example.com',
        'goal' => 'fitness',
        'token' => $token->token,
    ])
        ->assertOk()
        ->assertJson(['match' => true]);

    Event::assertDispatched(QueueEntryCreated::class);
});

it('allows a signup when phone and email match but the name differs', function (): void {
    Event::fake([QueueEntryCreated::class]);

    $location = Location::factory()->create();
    $token = LocationToken::factory()->signup()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    Member::factory()->create([
        'name' => 'Test Member',
        'contact' => '5550001111',
        'government_id' => 'GOV-11111',
        'email' => 'test@example.com',
        'status' => Status::Active,
    ]);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'signup',
        'name' => 'Different Name',
        'contact' => '5550001111',
        'government_id' => 'GOV-22222',
        'gender' => 'male',
        'dob' => '1995-06-15',
        'email' => 'test@example.com',
        'goal' => 'fitness',
        'token' => $token->token,
    ])
        ->assertOk()
        ->assertJsonStructure(['queue_entry_uuid']);

    Event::assertDispatched(QueueEntryCreated::class);
});

it('creates a signup queue entry when no member exists with the identifier', function (): void {
    Event::fake([QueueEntryCreated::class]);

    $location = Location::factory()->create();
    $token = LocationToken::factory()->signup()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $response = $this->postJson(route('checkin.submit'), [
        'kind' => 'signup',
        'name' => 'Test Member',
        'contact' => '5550001111',
        'government_id' => 'GOV-12345',
        'gender' => 'male',
        'dob' => '1995-06-15',
        'email' => 'test@example.com',
        'goal' => 'fitness',
        'token' => $token->token,
    ]);

    $response->assertOk()
        ->assertJson(['match' => true]);

    expect(QueueEntry::where('kind', 'signup')->count())->toBe(1);

    Event::assertDispatched(QueueEntryCreated::class, function (QueueEntryCreated $event): bool {
        return $event->kind === 'signup'
            && $event->position === 1
            && $event->payload['name'] === 'Test Member';
    });
});

it('matches a member by formatted phone number when the country code is configured', function (): void {
    Event::fake([QueueEntryCreated::class]);

    Helpers::setTestSettingsOverride([
        'general' => [
            'country' => 'India',
        ],
    ]);

    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $service = Service::factory()->create();
    $plan = Plan::factory()->create([
        'service_id' => $service->id,
        'track_uses' => false,
        'status' => Status::Active,
    ]);
    $member = Member::factory()->create([
        'contact' => '+919876543210',
        'status' => Status::Active,
    ]);
    Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => Carbon::today()->subDays(5),
        'end_date' => Carbon::today()->addDays(25),
    ]);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'identifier_type' => 'contact',
        'value' => '98765 43210',
        'token' => $token->token,
    ])->assertOk()
        ->assertJson(['match' => true])
        ->assertJsonPath('member.id', $member->id);
});

it('stores a normalized phone number on signup when the country code is configured', function (): void {
    Event::fake([QueueEntryCreated::class]);

    Helpers::setTestSettingsOverride([
        'general' => [
            'country' => 'India',
        ],
    ]);

    $location = Location::factory()->create();
    $token = LocationToken::factory()->signup()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'signup',
        'name' => 'Test Member',
        'contact' => '98765 43210',
        'emergency_contact' => '(987) 654-3210',
        'government_id' => 'GOV-12345',
        'gender' => 'male',
        'dob' => '1995-06-15',
        'token' => $token->token,
    ])->assertOk()
        ->assertJson(['match' => true]);

    $entry = QueueEntry::where('kind', 'signup')->first();

    expect($entry->payload['contact'])->toBe('+919876543210')
        ->and($entry->payload['emergency_contact'])->toBe('+919876543210');
});

it('renders the waiting page for an existing queue uuid', function (): void {
    $location = Location::factory()->create(['theme_color' => 'rose']);
    $queueEntry = QueueEntry::create([
        'uuid' => 'queue-test-uuid-1',
        'location_id' => $location->id,
        'kind' => 'checkin',
        'payload' => ['member_id' => 1],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->get(route('checkin.waiting', ['uuid' => $queueEntry->uuid]))
        ->assertOk()
        ->assertSee($queueEntry->uuid)
        ->assertSee('rose');
});

it('returns 404 for an unknown waiting uuid', function (): void {
    $this->get(route('checkin.waiting', ['uuid' => 'does-not-exist']))
        ->assertNotFound();
});

it('rejects invalid submit payloads', function (): void {
    $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'value' => '5551234567',
    ])->assertUnprocessable();
});

it('renders the contact-front-desk page with a 200 status', function (): void {
    $this->get(route('checkin.contact-front-desk'))
        ->assertOk()
        ->assertSee(__('app.scan.contact_front_desk_heading'))
        ->assertSee(__('app.scan.contact_front_desk_body'))
        ->assertSee(__('app.scan.contact_front_desk_contact'));
});

it('passes theme_color query param to the contact-front-desk page', function (): void {
    $this->get(route('checkin.contact-front-desk', ['theme_color' => 'indigo']))
        ->assertOk()
        ->assertSee('indigo');
});

it('the invalid-token 404 page uses the neutral contact-front-desk partial', function (): void {
    $this->get(route('checkin.scan', ['token' => 'deleted-token']))
        ->assertNotFound()
        ->assertSee('contact-container', false)
        ->assertSee('contact-icon', false)
        ->assertDontSee('invalid-icon', false);
});

it('shows the waiting page with member name and sign-up-another button for signup entries', function (): void {
    $location = Location::factory()->create();
    $signupToken = LocationToken::factory()->signup()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);
    $queueEntry = QueueEntry::create([
        'uuid' => 'signup-waiting-uuid',
        'location_id' => $location->id,
        'kind' => 'signup',
        'payload' => ['name' => 'Jane Doe', 'contact' => '5559990000'],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->get(route('checkin.waiting', ['uuid' => $queueEntry->uuid]))
        ->assertOk()
        ->assertSee('Jane Doe')
        ->assertSee(__('app.scan.sign_up_another'));
});

it('shows the waiting page without position for checkin entries', function (): void {
    $location = Location::factory()->create();
    $queueEntry = QueueEntry::create([
        'uuid' => 'checkin-waiting-uuid',
        'location_id' => $location->id,
        'kind' => 'checkin',
        'payload' => ['member_id' => 1],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->get(route('checkin.waiting', ['uuid' => $queueEntry->uuid]))
        ->assertOk()
        ->assertSee(__('app.scan.status_waiting'))
        ->assertDontSee(__('app.scan.sign_up_another'));
});
