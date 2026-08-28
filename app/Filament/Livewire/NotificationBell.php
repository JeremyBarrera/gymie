<?php

namespace App\Filament\Livewire;

use App\Support\AppConfig;
use App\Support\Dates\DeviceDateFormat;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Component;

class NotificationBell extends Component
{
    
    private const ALERT_ACTIONS = [
        'override_checkin',
        'new_subscription',
        'payment_added',
        'due_date_changed',
        'uses_exhausted_override',
    ];

    public const PER_LOAD = 20;

    public const UNREAD_BADGE_CAP = 9;

    
    public const TABS = ['active', 'archived'];

    public string $activeTab = 'active';

    public bool $modalOpen = false;

    
    public array $notifications = [];

    public bool $hasMore = false;

    protected $listeners = [
        'followUpEscalated' => 'onFollowUpEscalated',
    ];

    

    public function getUnreadCountProperty(): int
    {
        return auth()->user()->notifications()
            ->whereNull('read_at')
            ->whereNull('archived_at')
            ->count();
    }

    

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

    

    public function openBell(): void
    {
        $this->activeTab = 'active';
        $this->hasMore = false;
        $this->loadNotifications();

        $this->modalOpen = true;
        $this->dispatch('open-modal', id: 'notification-bell-modal');
    }

    

    public function closeBell(): void
    {
        if (! $this->modalOpen) {
            return;
        }

        
        
        
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

    

    public function loadNotifications(): void
    {
        [$rows, $hasMore] = $this->fetchNotifications();

        $this->notifications = $rows;
        $this->hasMore = $hasMore;
    }

    

    public function loadMore(): void
    {
        [$rows, $hasMore] = $this->fetchNotifications(count($this->notifications) + self::PER_LOAD);

        $this->notifications = $rows;
        $this->hasMore = $hasMore;
    }

    

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
        
        $notification = auth()->user()->notifications()
            ->whereKey($notificationId)
            ->first();

        return $notification;
    }

    

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
