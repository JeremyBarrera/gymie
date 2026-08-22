<?php

namespace App\Filament\Pages;

use App\Exceptions\PlanCheckIn\DuplicateCheckInRequiresConfirmationException;
use App\Exceptions\PlanCheckIn\PlanCheckInException;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn as PlanCheckInModel;
use App\Models\Service;
use App\Models\Subscription;
use App\Services\Membership\PlanCheckInService;
use App\Support\AppConfig;
use App\Support\Dates\DeviceDateFormat;
use App\Support\Locations\LocationAccess;
use Carbon\Carbon;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PlanCheckIn extends Page implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'check-in';

    protected string $view = 'filament.pages.plan-check-in';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return __('app.check_in.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('app.navigation.check_in');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('app.check_in.section_sign_in'))
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

    public function table(Table $table): Table
    {
        $timezone = AppConfig::timezone();
        $start = Carbon::today($timezone)->startOfDay();
        $end = Carbon::today($timezone)->endOfDay();

        return $table
            ->heading(__('app.check_in.section_today'))
            ->query(
                PlanCheckInModel::query()
                    ->with(['member', 'plan', 'service', 'location', 'checkedInBy'])
                    ->whereBetween('checked_in_at', [$start, $end])
                    ->when(
                        $this->locationScope(),
                        fn (Builder $query, array $locationIds): Builder => LocationAccess::applyAccessibleScope($query, 'location_id', $locationIds),
                    )
                    ->latest('checked_in_at')
            )
            ->columns([
                TextColumn::make('member.code')
                    ->label(__('app.fields.code'))
                    ->searchable(),
                TextColumn::make('member.name')
                    ->label(__('app.fields.name'))
                    ->searchable(),
                TextColumn::make('plan.name')
                    ->label(__('app.resources.plans.singular'))
                    ->description(fn (PlanCheckInModel $record): ?string => $record->plan?->code),
                TextColumn::make('service.name')
                    ->label(__('app.fields.service')),
                TextColumn::make('location.name')
                    ->label(__('app.fields.location'))
                    ->placeholder(__('app.placeholders.dash')),
                TextColumn::make('checked_in_at')
                    ->label(__('app.fields.checked_in_at'))
                    ->dateTime(DeviceDateFormat::dateTime()),
                TextColumn::make('checkedInBy.name')
                    ->label(__('app.fields.checked_in_by'))
                    ->placeholder(__('app.placeholders.dash')),
            ])
            ->filters([
                Filter::make('location')
                    ->schema([
                        Select::make('location_id')
                            ->label(__('app.fields.location'))
                            ->options(fn (): array => LocationAccess::locationOptions(Auth::user()))
                            ->searchable(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['location_id'] ?? null,
                        fn (Builder $query, int $locationId): Builder => $query->where('location_id', $locationId),
                    )),
                Filter::make('plan')
                    ->schema([
                        Select::make('plan_id')
                            ->label(__('app.resources.plans.singular'))
                            ->options(fn (): array => Plan::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['plan_id'] ?? null,
                        fn (Builder $query, int $planId): Builder => $query->where('plan_id', $planId),
                    )),
                Filter::make('service')
                    ->schema([
                        Select::make('service_id')
                            ->label(__('app.fields.service'))
                            ->options(fn (): array => Service::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['service_id'] ?? null,
                        fn (Builder $query, int $serviceId): Builder => $query->where('service_id', $serviceId),
                    )),
            ])
            ->emptyStateHeading(__('app.empty.no_check_ins_today'))
            ->emptyStateDescription(__('app.empty.no_check_ins_today_description'))
            ->defaultSort('checked_in_at', 'desc');
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

    /**
     * Location ids to scope today's check-ins to, or `null` to skip filtering.
     *
     * Super admins (and owners) see every location; any other account is limited
     * to its `user_locations` rows.
     *
     * @return list<int>|null
     */
    private function locationScope(): ?array
    {
        return LocationAccess::accessibleLocationIds(Auth::user());
    }
}
