<?php

namespace App\Filament\Pages;

use App\Support\AppConfig;
use App\Support\Dates\DeviceDateFormat;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\WithPagination;

/**
 * Notification center for the follow-up alert plan (LIVE_RECEPTION_FLOW_PLAN.md
 * phase O6): unread filter, per-item archive and a collapsed archive section.
 *
 * Every display field (member, actor, action, occurred_at) lives inside the
 * notification's JSON payload, so listing never queries related rows — no N+1
 * as alert volume grows. The private `user.{id}` Echo subscription lives in
 * `filament.pages.partials.user-channel-listener`; its handlers treat events
 * as refresh signals and re-query from the database.
 */
class Notifications extends Page
{
    use WithPagination;

    /** Contractual follow-up actions carrying an `app.follow_up.alert_*` label. */
    private const ALERT_ACTIONS = [
        'override_checkin',
        'new_subscription',
        'payment_added',
        'due_date_changed',
    ];

    private const PER_PAGE = 15;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    protected static ?string $slug = 'notifications';

    protected string $view = 'filament.pages.notifications';

    /**
     * @var list<array<string, mixed>> Collapsed archive section rows.
     */
    public array $archivedNotifications = [];

    public bool $unreadOnly = false;

    /**
     * Channel id for the `user.{id}` Echo subscription in
     * `filament.pages.partials.user-channel-listener`.
     */
    public function getUserChannelId(): ?int
    {
        return auth()->id();
    }

    protected $listeners = [
        'followUpEscalated' => 'onFollowUpEscalated',
    ];

    public function mount(): void
    {
        $this->loadNotifications();
    }

    public function getTitle(): string
    {
        return __('app.follow_up.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('app.follow_up.title');
    }

    /**
     * Active (non-archived) notifications honoring the unread filter.
     */
    public function activeNotifications(): LengthAwarePaginator
    {
        return auth()->user()->notifications()
            ->whereNull('archived_at')
            ->when($this->unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->paginate(self::PER_PAGE)
            ->through(fn (DatabaseNotification $notification): array => $this->displayRow($notification));
    }

    public function toggleUnreadOnly(): void
    {
        $this->unreadOnly = ! $this->unreadOnly;
        $this->resetPage();
    }

    /**
     * Re-fetch both sections; also bound to Echo reconnects so missed events
     * resync from the database.
     */
    public function loadNotifications(): void
    {
        $this->archivedNotifications = auth()->user()->notifications()
            ->whereNotNull('archived_at')
            ->orderByDesc('archived_at')
            ->get()
            ->map(fn (DatabaseNotification $notification): array => $this->displayRow($notification))
            ->all();
    }

    public function onFollowUpEscalated(array $payload = []): void
    {
        // Refresh signal only — the database row stays the source of truth,
        // so re-fetch instead of applying the payload as a client-side delta.
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

    public function markRead(string $notificationId): void
    {
        $notification = $this->findNotification($notificationId);

        if ($notification === null) {
            return;
        }

        $notification->markAsRead();

        $this->loadNotifications();
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
            'message' => $message,
            'reason' => $isContractAlert ? $reason : null,
            'member_code' => is_string($member['code'] ?? null) ? $member['code'] : null,
            'occurred_at' => $occurredAt === null
                ? __('app.placeholders.dash')
                : DeviceDateFormat::formatDateTime($occurredAt->timezone(AppConfig::timezone())),
        ];
    }
}
