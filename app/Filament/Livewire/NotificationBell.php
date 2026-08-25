<?php

namespace App\Filament\Livewire;

use App\Support\AppConfig;
use App\Support\Dates\DeviceDateFormat;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Topbar notification bell: one modal holding every notification of the
 * signed-in user across two tabs — Active (archived_at null) and Archive.
 *
 * Every display field (member, actor, action, occurred_at) lives inside the
 * notification's JSON payload, so listing never queries related rows — no
 * N+1 as alert volume grows. The private `user.{id}` Echo subscription lives
 * inside the component view; its handlers treat events as refresh signals
 * and re-query from the database, and socket reconnects resync the same way.
 *
 * Pagination choice: load-latest-N with a "load more" control. Inside a
 * modal, offset pagination links are clunky and lose scroll context; a
 * limit+1 fetch computes hasMore without an extra count query.
 */
class NotificationBell extends Component
{
    /** Contractual follow-up actions carrying an `app.follow_up.alert_*` label. */
    private const ALERT_ACTIONS = [
        'override_checkin',
        'new_subscription',
        'payment_added',
        'due_date_changed',
        'uses_exhausted_override',
    ];

    public const PER_LOAD = 20;

    public const UNREAD_BADGE_CAP = 9;

    /** @var list<string> Tabs of the bell modal. */
    public const TABS = ['active', 'archived'];

    public string $activeTab = 'active';

    public bool $modalOpen = false;

    /** @var list<array<string, mixed>> Display rows of the current tab. */
    public array $notifications = [];

    public bool $hasMore = false;

    protected $listeners = [
        'followUpEscalated' => 'onFollowUpEscalated',
    ];

    /**
     * Unread, non-archived notifications behind the topbar badge — archived
     * rows are deliberately filed away and no longer demand attention.
     */
    public function getUnreadCountProperty(): int
    {
        return auth()->user()->notifications()
            ->whereNull('read_at')
            ->whereNull('archived_at')
            ->count();
    }

    /**
     * Badge label capped at UNREAD_BADGE_CAP ("9+"), null when nothing to show.
     */
    public function getUnreadBadgeProperty(): ?string
    {
        $count = $this->unreadCount;

        if ($count === 0) {
            return null;
        }

        return $count > self::UNREAD_BADGE_CAP
            ? self::UNREAD_BADGE_CAP.'+'
            : (string) $count;
    }

    /**
     * Opens the single notification modal through Filament's modal manager,
     * resetting any previous tab/limit state first.
     */
    public function openBell(): void
    {
        $this->activeTab = 'active';
        $this->hasMore = false;
        $this->loadNotifications();

        $this->modalOpen = true;
        $this->dispatch('open-modal', id: 'notification-bell-modal');
    }

    /**
     * Idempotent close: reachable both from this component and from the
     * client closing the modal directly (X button, escape, click-away),
     * whose `modal-closed` echo must not loop into another dispatch.
     */
    public function closeBell(): void
    {
        if (! $this->modalOpen) {
            return;
        }

        // Close through Filament's modal manager BEFORE the state clear can
        // morph the modal out of the DOM — an unmount while open leaves a
        // stuck semi-transparent window stacked on top of the next modal.
        $this->dispatch('close-modal', id: 'notification-bell-modal');

        $this->modalOpen = false;
        $this->notifications = [];
        $this->hasMore = false;
    }

    public function switchTab(string $tab): void
    {
        if (! in_array($tab, self::TABS, true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->loadNotifications();
    }

    /**
     * Loads the latest PER_LOAD rows of the current tab; also bound to Echo
     * reconnects so events missed while disconnected resync from the database.
     */
    public function loadNotifications(): void
    {
        [$rows, $hasMore] = $this->fetchNotifications();

        $this->notifications = $rows;
        $this->hasMore = $hasMore;
    }

    /**
     * Extends the current tab by the next PER_LOAD rows — a fresh fetch that
     * replaces the list rather than a client-side delta merge.
     */
    public function loadMore(): void
    {
        [$rows, $hasMore] = $this->fetchNotifications(count($this->notifications) + self::PER_LOAD);

        $this->notifications = $rows;
        $this->hasMore = $hasMore;
    }

    /**
     * Refresh signal only — the database row stays the source of truth, so
     * re-fetch instead of applying the payload as a client-side delta.
     */
    public function onFollowUpEscalated(array $payload = []): void
    {
        $this->loadNotifications();
    }

    public function markRead(string $notificationId): void
    {
        $notification = $this->findNotification($notificationId);

        if ($notification === null) {
            return;
        }

        $notification->markAsRead();

        $this->loadNotifications();
    }

    public function archive(string $notificationId): void
    {
        $notification = $this->findNotification($notificationId);

        if ($notification === null || $notification->archived_at !== null) {
            return;
        }

        // forceFill bypasses DatabaseNotification's mass-assignment guards.
        $notification->forceFill(['archived_at' => now()])->save();

        $this->loadNotifications();
    }

    public function unarchive(string $notificationId): void
    {
        $notification = $this->findNotification($notificationId);

        if ($notification === null || $notification->archived_at === null) {
            return;
        }

        $notification->forceFill(['archived_at' => null])->save();

        $this->loadNotifications();
    }

    public function render(): View
    {
        return view('livewire.notification-bell');
    }

    /**
     * Fetches up to `$limit + 1` display rows of the current tab; the extra
     * row only answers "is there more?" without a second count query.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function fetchNotifications(?int $limit = null): array
    {
        $limit ??= self::PER_LOAD;

        $query = auth()->user()->notifications()
            ->when(
                $this->activeTab === 'active',
                fn ($query) => $query->whereNull('archived_at')->latest(),
                fn ($query) => $query->whereNotNull('archived_at')->orderByDesc('archived_at'),
            )
            ->limit($limit + 1)
            ->get();

        $hasMore = $query->count() > $limit;

        return [
            $query->take($limit)->map(fn (DatabaseNotification $notification): array => $this->displayRow($notification))->all(),
            $hasMore,
        ];
    }

    private function findNotification(string $notificationId): ?DatabaseNotification
    {
        /** @var DatabaseNotification|null $notification */
        $notification = auth()->user()->notifications()
            ->whereKey($notificationId)
            ->first();

        return $notification;
    }

    /**
     * @return array<string, mixed> Display-ready row built from the stored
     *                              payload — no related-row lookups.
     */
    private function displayRow(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];

        $action = is_string($data['action'] ?? null) ? $data['action'] : null;
        $actorName = is_array($data['actor'] ?? null) && is_string($data['actor']['name'] ?? null)
            ? $data['actor']['name']
            : null;
        $member = is_array($data['member'] ?? null) ? $data['member'] : [];
        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : null;

        $isContractAlert = $action !== null && in_array($action, self::ALERT_ACTIONS, true);

        $message = match (true) {
            $isContractAlert => __("app.follow_up.alert_{$action}", [
                'actor' => (string) $actorName,
                'member' => (string) ($member['name'] ?? ''),
            ]),
            filled($data['message'] ?? null) => (string) $data['message'],
            filled($reason) => $reason,
            default => __('app.follow_up.title'),
        };

        $occurredAt = is_string($data['occurred_at'] ?? null)
            ? Carbon::parse($data['occurred_at'])
            : $notification->created_at;

        return [
            'id' => (string) $notification->getKey(),
            'unread' => $notification->read_at === null,
            'archived' => $notification->archived_at !== null,
            'message' => $message,
            'reason' => $isContractAlert ? $reason : null,
            'member_code' => is_string($member['code'] ?? null) ? $member['code'] : null,
            'occurred_at' => $occurredAt === null
                ? __('app.placeholders.dash')
                : DeviceDateFormat::formatDateTime($occurredAt->timezone(AppConfig::timezone())),
        ];
    }
}
