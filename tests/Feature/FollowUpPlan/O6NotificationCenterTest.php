<?php

use App\Filament\Pages\Notifications as NotificationCenter;
use App\Models\User;
use App\Notifications\FollowUpAlertNotification;
use App\Support\AppConfig;
use App\Support\Dates\DeviceDateFormat;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Persist a follow-up alert for a recipient exactly the way the database
 * notification channel does, with a known UUID so same-second inserts never
 * make row retrieval ambiguous.
 */
function o6Send(User $recipient, array $payloadOverrides = []): DatabaseNotification
{
    $notification = new DatabaseNotification;
    $notification->forceFill([
        'id' => Str::uuid()->toString(),
        'type' => FollowUpAlertNotification::class,
        'data' => o6Payload($payloadOverrides),
    ]);

    $recipient->notifications()->save($notification);

    return $notification;
}

/**
 * @return array<string, mixed> The shared alert payload contract.
 */
function o6Payload(array $overrides = []): array
{
    return array_merge([
        'action' => 'override_checkin',
        'reason' => 'gate was busy',
        'actor' => ['id' => 999, 'name' => 'Toro'],
        'member' => ['id' => 888, 'name' => 'Jane Doe', 'code' => 'GY-42'],
        'subscription_id' => null,
        'invoice_id' => null,
        'occurred_at' => now()->toISOString(),
    ], $overrides);
}

function o6PerPage(): int
{
    return (new ReflectionClass(NotificationCenter::class))->getConstant('PER_PAGE');
}

it('requires authentication', function (): void {
    $this->get('/notifications')->assertRedirect();
});

it('lists active alerts with payload context and device-formatted occurred_at', function (): void {
    $owner = User::factory()->create();
    $notification = o6Send($owner);

    Livewire::actingAs($owner)
        ->test(NotificationCenter::class)
        ->assertOk()
        ->assertSee('Jane Doe')
        ->assertSee('Toro')
        ->assertSee('GY-42');

    /** @var DatabaseNotification $notification */
    $row = Livewire::test(NotificationCenter::class)
        ->instance()
        ->activeNotifications()
        ->items()[0];

    expect($row['unread'])->toBeTrue()
        ->and($row['message'])->toBe(__('app.follow_up.alert_override_checkin', [
            'actor' => 'Toro',
            'member' => 'Jane Doe',
        ]))
        ->and($row['reason'])->toBe('gate was busy')
        ->and($row['occurred_at'])->toBe(DeviceDateFormat::formatDateTime(
            Carbon::parse($notification->data['occurred_at'])->timezone(AppConfig::timezone()),
        ));
});

it('marks an alert as read on demand', function (): void {
    $owner = User::factory()->create();
    $notification = o6Send($owner);

    Livewire::actingAs($owner)
        ->test(NotificationCenter::class)
        ->call('markRead', (string) $notification->getKey())
        ->assertSet('archivedNotifications', []);

    expect($notification->fresh()->read_at)->not->toBeNull();

    $row = Livewire::test(NotificationCenter::class)
        ->instance()
        ->activeNotifications()
        ->items()[0];

    expect($row['unread'])->toBeFalse();
});

it('filters by unread when the toggle is on', function (): void {
    $owner = User::factory()->create();
    $read = o6Send($owner);
    $unreadA = o6Send($owner);
    $unreadB = o6Send($owner);

    DB::table('notifications')
        ->where('id', (string) $read->getKey())
        ->update(['read_at' => now()]);

    $component = Livewire::actingAs($owner)->test(NotificationCenter::class);

    expect($component->instance()->activeNotifications()->total())->toBe(3);

    $component->call('toggleUnreadOnly');

    expect($component->instance()->unreadOnly)->toBeTrue();

    $visibleIds = $component->instance()->activeNotifications()
        ->getCollection()
        ->pluck('id');

    expect($visibleIds)->toContain((string) $unreadA->getKey())
        ->toContain((string) $unreadB->getKey())
        ->not->toContain((string) $read->getKey());
});

it('archives an alert into the collapsed archive section', function (): void {
    $owner = User::factory()->create();
    $notification = o6Send($owner);
    $other = o6Send($owner);

    $component = Livewire::actingAs($owner)
        ->test(NotificationCenter::class)
        ->call('archive', (string) $notification->getKey());

    expect(DB::table('notifications')
        ->where('id', (string) $notification->getKey())
        ->value('archived_at'))->not->toBeNull()
        // Other alerts stay untouched.
        ->and(DB::table('notifications')
            ->where('id', (string) $other->getKey())
            ->value('archived_at'))->toBeNull();

    $component->assertSet(
        'archivedNotifications',
        fn (array $rows): bool => collect($rows)->pluck('id')->contains((string) $notification->getKey()),
    );

    expect($component->instance()->activeNotifications()
        ->getCollection()
        ->pluck('id'))
        ->not->toContain((string) $notification->getKey())
        ->toContain((string) $other->getKey());
});

it('excludes pre-existing archived alerts from the main list', function (): void {
    $owner = User::factory()->create();
    $archived = o6Send($owner);

    DB::table('notifications')
        ->where('id', (string) $archived->getKey())
        ->update(['archived_at' => now()]);

    $this->actingAs($owner)
        ->get('/notifications')
        ->assertOk()
        ->assertSee(__('app.notifications.archived'));

    $component = Livewire::test(NotificationCenter::class);

    expect($component->instance()->activeNotifications()->total())->toBe(0)
        ->and(collect($component->archivedNotifications))->toHaveCount(1);
});

it('archives only notifications that belong to the signed-in user', function (): void {
    $me = User::factory()->create();
    $someoneElse = User::factory()->create();
    $foreignNotification = o6Send($someoneElse);

    Livewire::actingAs($me)
        ->test(NotificationCenter::class)
        ->call('archive', (string) $foreignNotification->getKey())
        ->call('markRead', (string) $foreignNotification->getKey());

    $fresh = DB::table('notifications')
        ->where('id', (string) $foreignNotification->getKey())
        ->first();

    expect($me->notifications()->count())->toBe(0)
        ->and($fresh->read_at)->toBeNull()
        ->and($fresh->archived_at)->toBeNull();
});

it('re-fetches both sections when the escalation handler fires', function (): void {
    $owner = User::factory()->create();
    $alert = o6Send($owner);

    $component = Livewire::actingAs($owner)
        ->test(NotificationCenter::class);

    // Another tab archives the row after this component mounted; the event
    // payload must never be trusted — the handler re-queries the database.
    DB::table('notifications')
        ->where('id', (string) $alert->getKey())
        ->update(['archived_at' => now()]);

    $component->assertSet('archivedNotifications', [])
        ->call('onFollowUpEscalated', ['notification_id' => (string) $alert->getKey()])
        ->assertSet(
            'archivedNotifications',
            fn (array $rows): bool => collect($rows)->pluck('id')->contains((string) $alert->getKey()),
        );
});

it('registers the echo listener wiring and exposes its handlers', function (): void {
    $owner = User::factory()->create();

    $component = Livewire::actingAs($owner)->test(NotificationCenter::class);
    $listeners = (new ReflectionObject($component->instance()))
        ->getProperty('listeners')
        ->getValue($component->instance());

    expect($listeners)->toBe(['followUpEscalated' => 'onFollowUpEscalated'])
        ->and(method_exists($component->instance(), 'onFollowUpEscalated'))->toBeTrue()
        ->and(method_exists($component->instance(), 'loadNotifications'))->toBeTrue()
        ->and($component->instance()->getUserChannelId())->toBe($owner->id);

    $listener = file_get_contents(resource_path('views/filament/pages/partials/user-channel-listener.blade.php'));
    $view = file_get_contents(resource_path('views/filament/pages/notifications.blade.php'));

    expect($listener)->toContain('window.Echo.private(`user.')
        ->toContain(".listen('FollowUpEscalated'")
        ->toContain("@this.call('onFollowUpEscalated', e);")
        ->toContain("connection.bind('connected'")
        ->toContain("@this.call('loadNotifications');")
        ->toContain('! window.Echo')
        ->and($view)->toContain('filament.pages.partials.user-channel-listener');
});

it('paginates the active list', function (): void {
    $owner = User::factory()->create();

    $perPage = o6PerPage();
    $total = $perPage + 2;

    for ($i = 0; $i < $total; $i++) {
        o6Send($owner, ['action' => 'payment_added', 'reason' => "alert {$i}"]);
    }

    $component = Livewire::actingAs($owner)->test(NotificationCenter::class);
    $paginator = $component->instance()->activeNotifications();

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->total())->toBe($total)
        ->and($paginator->lastPage())->toBe(2)
        ->and(count($paginator->items()))->toBe($perPage);

    $component->call('gotoPage', 2);

    expect(count($component->instance()->activeNotifications()->items()))->toBe(2);
});

it('lists every user notification without per-row queries', function (): void {
    $owner = User::factory()->create();

    foreach ([1, 2, 3] as $index) {
        o6Send($owner, ['reason' => "warmup and baseline {$index}"]);
    }

    $component = Livewire::actingAs($owner)->test(NotificationCenter::class);

    // Warm relation caches so both measurements see identical conditions.
    $component->instance()->activeNotifications();
    $component->instance()->loadNotifications();

    DB::connection()->enableQueryLog();
    $component->instance()->activeNotifications();
    $component->instance()->loadNotifications();
    $baselineQueries = count(DB::connection()->getQueryLog());
    DB::flushQueryLog();
    DB::connection()->disableQueryLog();

    foreach ([4, 5, 6] as $index) {
        o6Send($owner, ['reason' => "extra alert {$index}"]);
    }

    DB::connection()->enableQueryLog();
    $component->instance()->activeNotifications();
    $component->instance()->loadNotifications();
    $grownQueries = count(DB::connection()->getQueryLog());
    DB::flushQueryLog();
    DB::connection()->disableQueryLog();

    // All display context rides in the JSON payload — tripling the row count
    // must not add a single query.
    expect($grownQueries)->toBe($baselineQueries);
});
