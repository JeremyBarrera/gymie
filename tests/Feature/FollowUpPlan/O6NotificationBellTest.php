<?php

use App\Filament\Livewire\NotificationBell;
use App\Models\User;
use App\Notifications\FollowUpAlertNotification;
use App\Support\AppConfig;
use App\Support\Dates\DeviceDateFormat;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
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

function o6Bell(User $user): Testable
{
    return Livewire::actingAs($user)->test(NotificationBell::class);
}

it('shows a capped unread badge and hides it when nothing is unread', function (): void {
    $owner = User::factory()->create();

    expect(o6Bell($owner)->instance()->unreadBadge)->toBeNull();

    foreach (range(1, 9) as $index) {
        o6Send($owner, ['reason' => "badge alert {$index}"]);
    }

    $component = o6Bell($owner);

    expect($component->instance()->unreadCount)->toBe(9)
        ->and($component->instance()->unreadBadge)->toBe('9')
        ->and($component->html())->toContain('fi-badge');

    o6Send($owner, ['reason' => 'the tenth unread alert']);

    $component = o6Bell($owner);

    expect($component->instance()->unreadCount)->toBe(10)
        ->and($component->instance()->unreadBadge)->toBe(NotificationBell::UNREAD_BADGE_CAP.'+');
});

it('does not count archived notifications in the badge', function (): void {
    $owner = User::factory()->create();
    $unread = o6Send($owner);

    DB::table('notifications')
        ->where('id', (string) $unread->getKey())
        ->update(['archived_at' => now()]);

    expect(o6Bell($owner)->instance()->unreadBadge)->toBeNull();
});

it('opens one modal through the filament modal manager and closes it before clearing state', function (): void {
    $owner = User::factory()->create();

    $component = o6Bell($owner)
        ->call('openBell')
        ->assertSet('modalOpen', true)
        ->assertDispatched('open-modal', id: 'notification-bell-modal');

    $component->call('closeBell')
        ->assertSet('modalOpen', false)
        ->assertDispatched('close-modal', id: 'notification-bell-modal')
        ->assertSet('notifications', []);

    // A second close (e.g. the client-side `modal-closed` echo) is a no-op.
    $component->call('closeBell')->assertNotDispatched('close-modal');
});

it('switches tabs and rejects unknown ones', function (): void {
    $owner = User::factory()->create();

    $component = o6Bell($owner)
        ->call('switchTab', 'archived')
        ->assertSet('activeTab', 'archived');

    $component->call('switchTab', '../users');

    expect($component->instance()->activeTab)->toBe('archived');
});

it('lists active alerts with payload context and device-formatted occurred_at', function (): void {
    $owner = User::factory()->create();
    $notification = o6Send($owner);

    $component = o6Bell($owner)
        ->call('openBell')
        ->assertSee('Jane Doe')
        ->assertSee('Toro')
        ->assertSee('GY-42');

    /** @var array<string, mixed> $row */
    $row = $component->instance()->notifications[0];

    expect($row['unread'])->toBeTrue()
        ->and($row['archived'])->toBeFalse()
        ->and($row['message'])->toBe(__('app.follow_up.alert_override_checkin', [
            'actor' => 'Toro',
            'member' => 'Jane Doe',
        ]))
        ->and($row['reason'])->toBe('gate was busy')
        ->and($row['occurred_at'])->toBe(DeviceDateFormat::formatDateTime(
            Carbon::parse($notification->data['occurred_at'])->timezone(AppConfig::timezone()),
        ));
});

it('lists archived alerts only under the archive tab', function (): void {
    $owner = User::factory()->create();
    $active = o6Send($owner, ['reason' => 'still on the active tab']);
    $archived = o6Send($owner, ['reason' => 'buried in the archive']);

    DB::table('notifications')
        ->where('id', (string) $archived->getKey())
        ->update(['archived_at' => now()]);

    o6Bell($owner)
        ->call('openBell')
        ->assertSee(__('app.notifications.tab_active'))
        ->assertDontSee('buried in the archive')
        ->call('switchTab', 'archived')
        ->assertSee('buried in the archive')
        ->assertDontSee('still on the active tab');

    $ids = collect(o6Bell($owner)->call('switchTab', 'archived')->instance()->notifications)
        ->pluck('id');

    expect($ids)->toContain((string) $archived->getKey())
        ->not->toContain((string) $active->getKey());
});

it('shows an empty state for both tabs when there is nothing to list', function (): void {
    $owner = User::factory()->create();

    o6Bell($owner)
        ->call('openBell')
        ->assertSee(__('app.notifications.empty_active'))
        ->assertDontSee(__('app.notifications.empty_archived'))
        ->call('switchTab', 'archived')
        ->assertSee(__('app.notifications.empty_archived'));
});

it('marks an alert as read on demand', function (): void {
    $owner = User::factory()->create();
    $notification = o6Send($owner);

    $component = o6Bell($owner)
        ->call('openBell')
        ->call('markRead', (string) $notification->getKey());

    expect(DB::table('notifications')
        ->where('id', (string) $notification->getKey())
        ->value('read_at'))->not->toBeNull()
        ->and($component->instance()->notifications[0]['unread'])->toBeFalse();
});

it('archives from the active tab and unarchives back from the archive tab', function (): void {
    $owner = User::factory()->create();
    $notification = o6Send($owner);
    $other = o6Send($owner);

    $component = o6Bell($owner)
        ->call('openBell')
        ->call('archive', (string) $notification->getKey());

    expect(DB::table('notifications')
        ->where('id', (string) $notification->getKey())
        ->value('archived_at'))->not->toBeNull()
        // Other alerts stay untouched.
        ->and(DB::table('notifications')
            ->where('id', (string) $other->getKey())
            ->value('archived_at'))->toBeNull()
        ->and(collect($component->instance()->notifications)->pluck('id'))
        ->not->toContain((string) $notification->getKey())
        ->toContain((string) $other->getKey());

    $component->call('switchTab', 'archived');

    expect(collect($component->instance()->notifications)->pluck('id'))
        ->toContain((string) $notification->getKey())
        ->not->toContain((string) $other->getKey());

    $component->call('unarchive', (string) $notification->getKey());

    expect(DB::table('notifications')
        ->where('id', (string) $notification->getKey())
        ->value('archived_at'))->toBeNull()
        ->and(collect($component->call('switchTab', 'active')->instance()->notifications)->pluck('id'))
        ->toContain((string) $notification->getKey());
});

it('never touches notifications that belong to another user', function (): void {
    $me = User::factory()->create();
    $someoneElse = User::factory()->create();
    $foreignNotification = o6Send($someoneElse);

    o6Bell($me)
        ->call('openBell')
        ->call('archive', (string) $foreignNotification->getKey())
        ->call('markRead', (string) $foreignNotification->getKey())
        ->call('unarchive', (string) $foreignNotification->getKey());

    $fresh = DB::table('notifications')
        ->where('id', (string) $foreignNotification->getKey())
        ->first();

    expect($me->notifications()->count())->toBe(0)
        ->and($fresh->read_at)->toBeNull()
        ->and($fresh->archived_at)->toBeNull();
});

it('re-fetches from the database when the escalation handler fires', function (): void {
    $owner = User::factory()->create();
    $alert = o6Send($owner);

    $component = o6Bell($owner)->call('openBell');

    // Another tab archives the row after this component mounted; the event
    // payload must never be trusted — the handler re-queries the database.
    DB::table('notifications')
        ->where('id', (string) $alert->getKey())
        ->update(['archived_at' => now()]);

    $component->call('onFollowUpEscalated', ['notification_id' => (string) $alert->getKey()])
        ->assertSet(
            'notifications',
            fn (array $rows): bool => collect($rows)->pluck('id')->isEmpty(),
        );
});

it('registers the echo listener wiring and exposes its handlers', function (): void {
    $owner = User::factory()->create();

    $component = o6Bell($owner);
    $listeners = (new ReflectionObject($component->instance()))
        ->getProperty('listeners')
        ->getValue($component->instance());

    expect($listeners)->toBe(['followUpEscalated' => 'onFollowUpEscalated'])
        ->and(method_exists($component->instance(), 'onFollowUpEscalated'))->toBeTrue()
        ->and(method_exists($component->instance(), 'loadNotifications'))->toBeTrue();

    $view = file_get_contents(resource_path('views/livewire/notification-bell.blade.php'));

    expect($view)->toContain('window.Echo.private(`user.')
        ->toContain(".listen('FollowUpEscalated'")
        ->toContain("@this.call('onFollowUpEscalated', e);")
        ->toContain("connection.bind('connected'")
        ->toContain("@this.call('loadNotifications');")
        ->toContain('! window.Echo');
});

it('loads the latest rows with load-more extending by one page', function (): void {
    $owner = User::factory()->create();

    $perPage = NotificationBell::PER_LOAD;
    $total = $perPage + 2;

    for ($i = 0; $i < $total; $i++) {
        o6Send($owner, ['action' => 'payment_added', 'reason' => "alert {$i}"]);
    }

    $component = o6Bell($owner)->call('openBell');

    expect(count($component->instance()->notifications))->toBe($perPage)
        ->and($component->instance()->hasMore)->toBeTrue()
        ->and($component->html())->toContain(__('app.notifications.load_more'));

    $component->call('loadMore');

    expect(count($component->instance()->notifications))->toBe($total)
        ->and($component->instance()->hasMore)->toBeFalse()
        ->and($component->html())->not->toContain(__('app.notifications.load_more'));
});

it('lists every user notification without per-row queries', function (): void {
    $owner = User::factory()->create();

    foreach ([1, 2, 3] as $index) {
        o6Send($owner, ['reason' => "warmup and baseline {$index}"]);
    }

    $component = o6Bell($owner);

    // Warm relation caches so both measurements see identical conditions.
    $component->instance()->loadNotifications();

    DB::connection()->enableQueryLog();
    $component->instance()->loadNotifications();
    $baselineQueries = count(DB::connection()->getQueryLog());
    DB::flushQueryLog();
    DB::connection()->disableQueryLog();

    foreach ([4, 5, 6] as $index) {
        o6Send($owner, ['reason' => "extra alert {$index}"]);
    }

    DB::connection()->enableQueryLog();
    $component->instance()->loadNotifications();
    $grownQueries = count(DB::connection()->getQueryLog());
    DB::flushQueryLog();
    DB::connection()->disableQueryLog();

    // All display context rides in the JSON payload — doubling the row count
    // must not add a single query.
    expect($grownQueries)->toBe($baselineQueries);
});

it('removes the legacy dedicated notification center artifacts', function (): void {
    $owner = User::factory()->create();

    expect(file_exists(app_path('Filament/Pages/Notifications.php')))->toBeFalse()
        ->and(file_exists(resource_path('views/filament/pages/notifications.blade.php')))->toBeFalse()
        ->and(file_exists(resource_path('views/filament/pages/partials/notification-item.blade.php')))->toBeFalse()
        ->and(file_exists(resource_path('views/filament/pages/partials/user-channel-listener.blade.php')))->toBeFalse()
        // Deleting the page class removes its route with it.
        ->and($this->actingAs($owner)->get('/notifications')->status())->toBe(404);
});

it('disables the native database notification pipeline on the admin panel', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->getId())->toBe('admin')
        ->and($panel->hasDatabaseNotifications())->toBeFalse();
});
