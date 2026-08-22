<?php

namespace App\Filament\Pages;

use App\Events\QueueEntryClaimed;
use App\Events\QueueEntryExpired;
use App\Events\QueueEntryResolved;
use App\Exceptions\PlanCheckIn\DuplicateCheckInRequiresConfirmationException;
use App\Exceptions\PlanCheckIn\PlanCheckInException;
use App\Filament\Concerns\HandlesCheckInVerification;
use App\Filament\Concerns\HandlesSignupVerification;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\QueueEntry;
use App\Models\Subscription;
use App\Notifications\ReceptionOverrideNotification;
use App\Services\Membership\PlanCheckInService;
use App\Support\DevOps\FeatureFlags;
use App\Support\Locations\LocationAccess;
use App\Support\Notifications\NotificationRecipients;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Reception extends Page implements HasActions, HasForms
{
    use HandlesCheckInVerification;
    use HandlesSignupVerification;
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $slug = 'reception';

    public static function getNavigationLabel(): string
    {
        return __('app.navigation.reception');
    }

    public static function getNavigationSort(): int
    {
        return -1;
    }

    protected string $view = 'filament.pages.reception';

    public string $activeTab = 'checkin';

    public ?string $selectedQueueEntryId = null;

    public bool $showConfirmOverlay = false;

    public ?string $confirmAction = null;

    public string $denyReason = '';

    /** @var array<int> Sign-up entries that arrived while another modal was open. */
    public array $checkinEntries = [];

    public array $signupEntries = [];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected $listeners = [
        'queueEntryCreated' => 'onQueueEntryCreated',
        'queueEntryClaimed' => 'onQueueEntryClaimed',
        'queueEntryResolved' => 'onQueueEntryResolved',
        'queueEntryExpired' => 'onQueueEntryExpired',
    ];

    public function mount(): void
    {
        $this->form->fill();
        $this->loadQueueEntries();
    }

    public function getTitle(): string
    {
        return __('app.reception.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('app.check_in.section_sign_in'))
                    ->icon('heroicon-o-identification')
                    ->schema([
                        Select::make('member_id')
                            ->label(__('app.resources.members.singular'))
                            ->placeholder(__('app.placeholders.select_member'))
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search): array {
                                return Member::query()
                                    ->where(function (Builder $query) use ($search): void {
                                        $query->where('name', 'like', "%{$search}%")
                                            ->orWhere('code', 'like', "%{$search}%")
                                            ->orWhere('government_id', 'like', "%{$search}%")
                                            ->orWhere('contact', 'like', "%{$search}%");
                                    })
                                    ->orderBy('name')
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (Member $record): array => [
                                        $record->id => "{$record->code} - {$record->name}",
                                    ])
                                    ->all();
                            })
                            ->getOptionLabelUsing(function ($value): ?string {
                                $member = Member::query()->find($value);

                                return $member ? "{$member->code} - {$member->name}" : null;
                            })
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('subscription_id', null))
                            ->required(),
                        Select::make('subscription_id')
                            ->label(__('app.resources.subscriptions.singular'))
                            ->placeholder(__('app.placeholders.select_plan'))
                            ->options(function (callable $get): array {
                                $memberId = $get('member_id');

                                if (blank($memberId)) {
                                    return [];
                                }

                                $member = Member::query()->find($memberId);

                                if ($member === null) {
                                    return [];
                                }

                                return app(PlanCheckInService::class)
                                    ->eligibleSubscriptions($member)
                                    ->mapWithKeys(fn (Subscription $subscription): array => [
                                        $subscription->id => app(PlanCheckInService::class)
                                            ->subscriptionOptionLabel($subscription),
                                    ])
                                    ->all();
                            })
                            ->searchable()
                            ->required()
                            ->visible(fn (callable $get): bool => filled($get('member_id')))
                            ->helperText(function (callable $get): ?string {
                                $memberId = $get('member_id');

                                if (blank($memberId)) {
                                    return null;
                                }

                                $member = Member::query()->find($memberId);

                                if ($member === null) {
                                    return null;
                                }

                                return app(PlanCheckInService::class)
                                    ->eligibleSubscriptions($member)
                                    ->isEmpty()
                                    ? __('app.empty.no_eligible_plans')
                                    : null;
                            }),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('signIn')
                ->label(__('app.actions.sign_in'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation(fn (): bool => $this->wouldDuplicateToday())
                ->modalHeading(__('app.check_in.confirm_duplicate_heading'))
                ->modalDescription(__('app.check_in.confirm_duplicate_description'))
                ->modalSubmitActionLabel(__('app.actions.confirm_sign_in'))
                ->action(function (): void {
                    $this->performCheckIn($this->wouldDuplicateToday());
                })
                ->disabled(fn (): bool => blank($this->data['member_id'] ?? null) || blank($this->data['subscription_id'] ?? null)),
        ];
    }

    public function performCheckIn(bool $confirmDuplicate = false): void
    {
        $memberId = $this->data['member_id'] ?? null;
        $subscriptionId = $this->data['subscription_id'] ?? null;

        if (blank($memberId) || blank($subscriptionId)) {
            return;
        }

        $member = Member::query()->findOrFail($memberId);
        $subscription = Subscription::query()->findOrFail($subscriptionId);

        try {
            $checkIn = app(PlanCheckInService::class)->checkIn(
                $member,
                $subscription,
                Auth::user(),
                $confirmDuplicate,
            );

            $checkIn->loadMissing('plan');
            $remaining = app(PlanCheckInService::class)->remainingUses($subscription);
            $usesLabel = $remaining === null
                ? __('app.fields.unlimited')
                : __('app.fields.uses_remaining', ['count' => $remaining]);

            Notification::make()
                ->title(__('app.notifications.check_in_success'))
                ->body(__('app.notifications.check_in_success_body', [
                    'member' => $member->name,
                    'plan' => $checkIn->plan?->name ?? '',
                    'uses' => $usesLabel,
                ]))
                ->success()
                ->send();

            $this->data = [];
            $this->form->fill();
        } catch (DuplicateCheckInRequiresConfirmationException $exception) {
            Notification::make()
                ->title(__('app.notifications.check_in_failed'))
                ->body($exception->getMessage())
                ->warning()
                ->send();
        } catch (PlanCheckInException $exception) {
            Notification::make()
                ->title(__('app.notifications.check_in_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function wouldDuplicateToday(): bool
    {
        $subscriptionId = $this->data['subscription_id'] ?? null;

        if (blank($subscriptionId)) {
            return false;
        }

        $subscription = Subscription::query()->find($subscriptionId);

        if ($subscription === null) {
            return false;
        }

        return app(PlanCheckInService::class)->hasCheckedInToday($subscription);
    }

    public function loadQueueEntries(): void
    {
        $locationId = $this->getCurrentLocationId();
        if (! $locationId) {
            $this->checkinEntries = [];
            $this->signupEntries = [];

            return;
        }

        $this->checkinEntries = QueueEntry::query()
            ->where('location_id', $locationId)
            ->where('kind', 'checkin')
            ->whereIn('status', ['waiting', 'attending'])
            ->latest('created_at')
            ->get()
            ->toArray();

        $this->signupEntries = QueueEntry::query()
            ->where('location_id', $locationId)
            ->where('kind', 'signup')
            ->whereIn('status', ['waiting', 'attending'])
            ->latest('created_at')
            ->get()
            ->toArray();

        $this->closeStaleOverlays();
    }

    /**
     * Close an open overlay whose entry is no longer pending — covers missed
     * broadcast events (e.g. the entry was handled in another tab).
     */
    private function closeStaleOverlays(): void
    {
        $hasSignupOverlay = $this->selectedQueueEntryId && ($this->showVerifyOverlay || $this->showConfirmOverlay);
        $hasCheckInOverlay = $this->selectedCheckInEntryId && $this->showCheckInOverlay;

        if (! $hasSignupOverlay && ! $hasCheckInOverlay) {
            return;
        }

        if ($hasSignupOverlay) {
            $entry = QueueEntry::find($this->selectedQueueEntryId);
            $stillActive = $entry !== null && in_array($entry->status, ['waiting', 'attending'], true);

            if ($stillActive) {
                return;
            }

            if ($this->showVerifyOverlay) {
                $this->closeVerifyOverlay();
            } else {
                $this->closeConfirmOverlay();
            }
        }

        if ($hasCheckInOverlay) {
            $entry = QueueEntry::find($this->selectedCheckInEntryId);
            $stillActive = $entry !== null && in_array($entry->status, ['waiting', 'attending'], true);

            if (! $stillActive) {
                $this->closeCheckInOverlay();
            }
        }
    }

    private function getCurrentLocationId(): ?int
    {
        return LocationAccess::firstAccessibleLocationId(Auth::user());
    }

    public function onQueueEntryCreated(array $payload): void
    {
        $entry = QueueEntry::find($payload['queueEntryId'] ?? 0);
        if (! $entry) {
            return;
        }

        $entryArray = $entry->toArray();

        if ($entry->kind === 'checkin') {
            $this->prependEntryIfMissing($this->checkinEntries, $entryArray);
            $this->queueOrOpenCheckIn($entry);
        } elseif ($entry->kind === 'signup') {
            $this->prependEntryIfMissing($this->signupEntries, $entryArray);

            if (isset($entry->payload['name'])) {
                $this->dispatch('notify',
                    type: 'success',
                    message: __('app.reception.new_signup', ['name' => $entry->payload['name']]),
                );
            }

            $this->queueOrOpenSignup($entry);
        }
    }

    public function onQueueEntryClaimed(array $payload): void
    {
        $entry = QueueEntry::find($payload['queueEntryId'] ?? 0);
        if (! $entry) {
            return;
        }

        $this->updateEntryInLists($entry->id, $entry->toArray());
    }

    public function onQueueEntryResolved(array $payload): void
    {
        $entry = QueueEntry::find($payload['queueEntryId'] ?? 0);
        if (! $entry) {
            return;
        }

        $this->removeEntryFromLists($entry->id);
        $this->closeOverlayIfStale($entry->id, true);
    }

    public function onQueueEntryExpired(array $payload): void
    {
        $entry = QueueEntry::find($payload['queueEntryId'] ?? 0);
        if (! $entry) {
            return;
        }

        $this->removeEntryFromLists($entry->id);
        $this->closeOverlayIfStale($entry->id, false);
    }

    /**
     * Close the open overlay when the entry it shows was handled or expired
     * by another tab / device, so every tab stays in sync.
     */
    private function closeOverlayIfStale(int $queueEntryId, bool $notifyHandledElsewhere): void
    {
        $isConfirm = $this->showConfirmOverlay && (int) $this->selectedQueueEntryId === $queueEntryId;

        if ($isConfirm) {
            $this->closeConfirmOverlay();

            if ($notifyHandledElsewhere) {
                $this->dispatch('notify',
                    type: 'warning',
                    message: __('app.reception.handled_elsewhere'),
                );
            }

            return;
        }

        $isCheckIn = $this->showCheckInOverlay && (int) $this->selectedCheckInEntryId === $queueEntryId;

        if ($isCheckIn) {
            $this->closeCheckInOverlay();

            if ($notifyHandledElsewhere) {
                $this->dispatch('notify',
                    type: 'warning',
                    message: __('app.reception.handled_elsewhere'),
                );
            }

            return;
        }

        $this->closeVerifyIfStale($queueEntryId, $notifyHandledElsewhere);
    }

    private function prependEntryIfMissing(array &$list, array $entry): void
    {
        if (array_search($entry['id'], array_column($list, 'id'), true) === false) {
            array_unshift($list, $entry);
        }
    }

    private function removeEntryFromLists(int $queueEntryId): void
    {
        foreach (['checkinEntries', 'signupEntries'] as $listName) {
            $index = array_search($queueEntryId, array_column($this->{$listName}, 'id'));
            if ($index !== false) {
                unset($this->{$listName}[$index]);
                $this->{$listName} = array_values($this->{$listName});
                break;
            }
        }
    }

    public function deleteQueueEntry(int $queueEntryId): void
    {
        $entry = QueueEntry::find($queueEntryId);
        if (! $entry) {
            return;
        }

        $locationToken = $entry->location->tokens()
            ->where('kind', $entry->kind)
            ->value('token');

        QueueEntryExpired::dispatch($entry->id, $entry->uuid, $locationToken ?? '');

        $entry->delete();

        $this->removeEntryFromLists($queueEntryId);

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.queue_deleted'),
        );
    }

    private function updateEntryInLists(int $queueEntryId, array $data): void
    {
        foreach (['checkinEntries', 'signupEntries'] as $listName) {
            $index = array_search($queueEntryId, array_column($this->{$listName}, 'id'));
            if ($index !== false) {
                foreach ($data as $key => $value) {
                    $this->{$listName}[$index][$key] = $value;
                }
                if (in_array($data['status'] ?? '', ['approved', 'denied', 'expired'])) {
                    unset($this->{$listName}[$index]);
                    $this->{$listName} = array_values($this->{$listName});
                }
                break;
            }
        }

    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->selectedQueueEntryId = null;
        $this->showConfirmOverlay = false;
        $this->showVerifyOverlay = false;
        $this->verifyStep = 1;
        $this->verifyPhoto = null;
        $this->verifyForm = [];
        $this->unseenSignupIds = [];
        $this->showCheckInOverlay = false;
        $this->selectedCheckInEntryId = null;
        $this->selectedCheckInMemberId = null;
        $this->checkInServiceId = null;
        $this->checkInDenyStep = false;
        $this->checkInDenyReason = '';
        $this->checkInOverrideStep = false;
        $this->checkInOverrideReason = '';
        $this->checkInOverrideRecipients = [];
        $this->checkInPopupQueue = [];
    }

    public function claim(int $queueEntryId): void
    {
        $updated = QueueEntry::where('id', $queueEntryId)
            ->where('status', 'waiting')
            ->whereNull('claimed_by_user_id')
            ->update([
                'claimed_by_user_id' => Auth::id(),
                'claimed_at' => now(),
                'status' => 'attending',
            ]);

        if (! $updated) {
            $this->dispatch('notify',
                type: 'warning',
                message: __('app.reception.already_claimed'),
            );

            return;
        }

        $entry = QueueEntry::find($queueEntryId);
        if ($entry) {
            $locationToken = LocationToken::where('tokenable_type', Location::class)
                ->where('tokenable_id', $entry->location_id)
                ->where('kind', $entry->kind)
                ->value('token');

            if ($locationToken) {
                broadcast(new QueueEntryClaimed(
                    $entry->id,
                    $entry->uuid,
                    $locationToken,
                    $entry->kind,
                    $entry->payload,
                    Auth::user()->name
                ))->toOthers();
            }
        }

        $this->updateEntryInLists($queueEntryId, [
            'status' => 'attending',
            'claimed_by_user_id' => Auth::id(),
            'claimed_at' => now(),
        ]);

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.claimed'),
        );

        if ($entry && $entry->kind === 'checkin') {
            $this->openCheckInOverlay($entry->id);
        }
    }

    public function openConfirmOverlay(int $queueEntryId, string $action): void
    {
        $this->selectedQueueEntryId = $queueEntryId;
        $this->confirmAction = $action;
        $this->denyReason = '';
        $this->showConfirmOverlay = true;
    }

    public function closeConfirmOverlay(): void
    {
        $this->showConfirmOverlay = false;
        $this->selectedQueueEntryId = null;
        $this->confirmAction = null;
        $this->denyReason = '';
    }

    protected function removeApprovedSignupFromView(int $queueEntryId): void
    {
        $this->removeEntryFromLists($queueEntryId);
    }

    protected function removeCheckInFromView(int $queueEntryId): void
    {
        $this->removeEntryFromLists($queueEntryId);
    }

    public function confirm(): void
    {
        if (! $this->selectedQueueEntryId || ! $this->confirmAction) {
            return;
        }

        $entry = QueueEntry::find($this->selectedQueueEntryId);
        if (! $entry) {
            $this->closeConfirmOverlay();

            return;
        }

        $locationToken = LocationToken::where('tokenable_type', Location::class)
            ->where('tokenable_id', $entry->location_id)
            ->where('kind', $entry->kind)
            ->value('token');

        match ($this->confirmAction) {
            'approve' => $entry->kind === 'signup'
                ? $this->openVerifyOverlay($entry->id)
                : $this->approve($entry, $locationToken),
            'deny' => $this->deny($entry, $locationToken),
            'override' => $this->override($entry, $locationToken),
        };

        $this->closeConfirmOverlay();
    }

    private function approve(QueueEntry $entry, ?string $locationToken): void
    {
        if ($entry->kind === 'checkin') {
            $memberId = $entry->payload['member_id'] ?? null;
            $subscriptionId = $entry->payload['subscription_id'] ?? null;

            if ($memberId && $subscriptionId) {
                try {
                    $member = Member::findOrFail($memberId);
                    $subscription = Subscription::findOrFail($subscriptionId);

                    app(PlanCheckInService::class)->checkIn(
                        $member,
                        $subscription,
                        Auth::user(),
                        false
                    );

                    $entry->update(['status' => 'approved', 'override' => false]);
                } catch (\Throwable $e) {
                    Log::error('Reception approve failed', ['error' => $e->getMessage()]);
                    $this->dispatch('notify',
                        type: 'danger',
                        message: $e->getMessage(),
                    );

                    return;
                }
            }
        } else {
            $entry->update(['status' => 'approved', 'override' => false]);
        }

        if ($locationToken) {
            broadcast(new QueueEntryResolved(
                $entry->id,
                $entry->uuid,
                $locationToken,
                $entry->kind,
                $entry->payload,
                true,
                null
            ))->toOthers();
        }

        $this->updateEntryInLists($entry->id, ['status' => 'approved']);
        $this->dispatch('notify', type: 'success', message: __('app.reception.approved'));
    }

    private function deny(QueueEntry $entry, ?string $locationToken): void
    {
        $reason = $this->denyReason ?: __('app.reception.denied_no_reason');

        $entry->update([
            'status' => 'denied',
            'denied_reason' => $reason,
            'override' => false,
        ]);

        if ($locationToken) {
            broadcast(new QueueEntryResolved(
                $entry->id,
                $entry->uuid,
                $locationToken,
                $entry->kind,
                $entry->payload,
                false,
                $reason
            ))->toOthers();
        }

        $this->updateEntryInLists($entry->id, [
            'status' => 'denied',
            'denied_reason' => $reason,
        ]);

        $this->dispatch('notify', type: 'success', message: __('app.reception.denied'));
    }

    private function override(QueueEntry $entry, ?string $locationToken): void
    {
        if (! FeatureFlags::activeForUser(Auth::user(), 'checkin.override')) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.reception.override_disabled'),
            );

            return;
        }

        if ($entry->kind === 'checkin') {
            $memberId = $entry->payload['member_id'] ?? null;

            if ($memberId) {
                try {
                    $member = Member::findOrFail($memberId);
                    $subscription = Subscription::find($entry->payload['subscription_id'] ?? null);

                    app(PlanCheckInService::class)->checkInOverride(
                        $member,
                        $subscription,
                        Auth::user(),
                        null,
                        false,
                        $subscription?->plan?->service_id,
                    );

                    $entry->update([
                        'status' => 'approved',
                        'override' => true,
                        'override_by_user_id' => Auth::id(),
                    ]);

                    $this->notifyRecipients($entry, $member);
                } catch (\Throwable $e) {
                    Log::error('Reception override failed', ['error' => $e->getMessage()]);
                    $this->dispatch('notify',
                        type: 'danger',
                        message: $e->getMessage(),
                    );

                    return;
                }
            }
        } else {
            $entry->update([
                'status' => 'approved',
                'override' => true,
                'override_by_user_id' => Auth::id(),
            ]);
        }

        if ($locationToken) {
            broadcast(new QueueEntryResolved(
                $entry->id,
                $entry->uuid,
                $locationToken,
                $entry->kind,
                $entry->payload,
                true,
                null
            ))->toOthers();
        }

        $this->updateEntryInLists($entry->id, ['status' => 'approved']);
        $this->dispatch('notify', type: 'warning', message: __('app.reception.override_approved'));
    }

    private function notifyRecipients(QueueEntry $entry, Member $member): void
    {
        $recipients = NotificationRecipients::resolve('override');

        foreach ($recipients as $user) {
            $user->notify(new ReceptionOverrideNotification($entry, $member, Auth::user()));
        }
    }
}
