<?php

namespace App\Filament\Resources\Members\Tables;

use App\Events\MemberBanChanged;
use App\Models\LocationToken;
use App\Models\Member;
use App\Support\Dates\DeviceDateFormat;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;
use Throwable;

class MemberTable
{
    /**
     * Ban / unban toggles shared by the row dropdown, the member-view
     * header and the bulk action. Custom abilities get no default policy
     * authorization, so 'Ban:Member' is checked for rendering AND enforced
     * again inside the action itself.
     */
    public static function banAction(): Action
    {
        return Action::make('ban')
            ->authorize(fn (Member $record): bool => Gate::allows('ban', $record))
            ->color('danger')
            ->label(__('app.members.ban_action'))
            ->icon('heroicon-m-no-symbol')
            ->requiresConfirmation()
            ->modalHeading(__('app.members.ban_confirm_title'))
            ->modalDescription(__('app.members.ban_confirm_body'))
            ->modalSubmitActionLabel(__('app.members.ban_action'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('app.members.ban_reason'))
                    ->placeholder(__('app.members.ban_reason_optional'))
                    ->maxLength(500)
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->action(function (Member $record, array $data): void {
                Gate::authorize('ban', $record);

                DB::transaction(function () use ($record, $data): void {
                    $record->update([
                        'status' => 'banned',
                        'ban_reason' => filled($data['reason'] ?? null) ? $data['reason'] : null,
                    ]);
                });

                MemberBanChanged::dispatch(
                    memberId: (int) $record->id,
                    banned: true,
                    locationTokens: LocationToken::query()->pluck('token')->all(),
                );

                Notification::make()
                    ->title(__('app.members.banned_toast'))
                    ->danger()
                    ->send();
            })
            ->visible(fn (Member $record): bool => $record->status?->value !== 'banned');
    }

    public static function unbanAction(): Action
    {
        return Action::make('unban')
            ->authorize(fn (Member $record): bool => Gate::allows('unban', $record))
            ->color('success')
            ->label(__('app.members.unban_action'))
            ->icon('heroicon-m-check-circle')
            ->requiresConfirmation()
            ->modalSubmitActionLabel(__('app.members.unban_action'))
            ->action(function (Member $record): void {
                Gate::authorize('unban', $record);

                DB::transaction(function () use ($record): void {
                    $record->update([
                        'status' => 'active',
                        'ban_reason' => null,
                    ]);
                });

                MemberBanChanged::dispatch(
                    memberId: (int) $record->id,
                    banned: false,
                    locationTokens: LocationToken::query()->pluck('token')->all(),
                );

                Notification::make()
                    ->title(__('app.members.unbanned_toast'))
                    ->success()
                    ->send();
            })
            ->visible(fn (Member $record): bool => $record->status?->value === 'banned');
    }

    /**
     * Configure the member table schema.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                ImageColumn::make('photo')
                    ->circular()
                    ->defaultImageUrl(fn (Member $record): string => 'https://ui-avatars.com/api/?background=000&color=fff&name='.$record->name),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable()
                    ->label(__('app.fields.name')),
                TextColumn::make('email')
                    ->searchable()
                    ->label(__('app.fields.email')),
                TextColumn::make('government_id')
                    ->searchable()
                    ->label(__('app.fields.government_id')),
                TextColumn::make('gender')
                    ->searchable()
                    ->label(__('app.fields.gender')),
                TextColumn::make('contact')
                    ->searchable()
                    ->label(__('app.fields.contact')),
                TextColumn::make('emergency_contact')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('app.fields.emergency_contact')),
                TextColumn::make('created_at')
                    ->sortable()
                    ->date(DeviceDateFormat::date())
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('app.fields.date')),
                TextColumn::make('status')
                    ->badge()
                    ->tooltip(fn (Member $record): ?string => $record->status?->value === 'banned'
                        ? (string) $record->ban_reason
                        : null)
                    ->label(__('app.fields.status')),
            ])
            ->emptyStateIcon('heroicon-o-user-group')
            ->emptyStateHeading(function ($livewire): string {
                $dates = $livewire->getTableFilterState('date') ?? [];
                [$from, $to] = [$dates['date_from'] ?? null, $dates['date_to'] ?? null];
                $records = (string) __('app.resources.members.plural');
                $tab = (string) ($livewire->activeTab ?? 'all');
                $status = $tab !== 'all' ? (string) __('app.status.'.$tab) : null;

                if (! $from && ! $to) {
                    return $status
                        ? __('app.empty.no_status_records', ['status' => $status, 'records' => $records])
                        : __('app.empty.no_records', ['records' => $records]);
                }

                if ($tab === 'all') {
                    return __('app.empty.no_records_in_range', ['records' => $records]);
                }

                $base = __('app.empty.no_status_records', ['status' => $status, 'records' => $records]);

                return Member::where('status', $tab)->exists()
                    ? __('app.empty.no_status_records_in_range', ['status' => $status, 'records' => $records])
                    : $base;
            })
            ->emptyStateDescription(function ($livewire): string {
                $dates = $livewire->getTableFilterState('date') ?? [];
                [$fromRaw, $toRaw] = [$dates['date_from'] ?? null, $dates['date_to'] ?? null];
                $records = (string) __('app.resources.members.plural');
                $record = (string) __('app.resources.members.singular');
                $tab = (string) ($livewire->activeTab ?? 'all');
                $status = $tab !== 'all' ? (string) __('app.status.'.$tab) : null;

                if (! $fromRaw && ! $toRaw) {
                    return $status
                        ? __('app.empty.no_records_marked_as', ['records' => $records, 'status' => $status])
                        : __('app.empty.create_to_get_started', ['resource' => $record]);
                }

                $from = $fromRaw ? Carbon::parse($fromRaw)->translatedFormat(DeviceDateFormat::date()) : (string) __('app.common.the_beginning');
                $to = $toRaw ? Carbon::parse($toRaw)->translatedFormat(DeviceDateFormat::date()) : (string) __('app.common.today');

                if ($tab === 'all') {
                    return __('app.empty.found_none_between', ['records' => $records, 'from' => $from, 'to' => $to]);
                }

                if (! Member::where('status', $tab)->exists()) {
                    return __('app.empty.no_records_marked_as', ['records' => $records, 'status' => $status]);
                }

                return __('app.empty.found_none_status_between', ['status' => $status, 'records' => $records, 'from' => $from, 'to' => $to]);
            })
            ->emptyStateActions([
                CreateAction::make()
                    ->icon('heroicon-o-plus')
                    ->label(__('app.actions.new', ['resource' => __('app.resources.members.singular')]))
                    ->hidden(fn (): bool => Member::exists()),
            ])
            ->filters([
                TrashedFilter::make(),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('date_from')
                            ->label(__('app.fields.date_from'))
                            ->native(false)
                            ->suffixIcon('heroicon-m-calendar-days'),
                        DatePicker::make('date_to')
                            ->label(__('app.fields.date_to'))
                            ->native(false)
                            ->suffixIcon('heroicon-m-calendar-days'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['date_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['date_to'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    ActionGroup::make([
                        Action::make('heading_actions')
                            ->label(__('app.fields.status'))
                            ->disabled()
                            ->color('gray'),
                        Action::make('mark_as_active')
                            ->color('success')
                            ->label(__('app.actions.mark_as_active'))
                            ->requiresConfirmation()
                            ->action(fn (Member $record) => tap($record, function ($record) {
                                $record->update(['status' => 'active']);
                                Notification::make()
                                    ->title(__('app.notifications.member_activated'))
                                    ->success()
                                    ->send();
                            }))
                            ->visible(fn ($record) => $record->status->value === 'inactive'),
                        Action::make('mark_as_inactive')
                            ->color('danger')
                            ->label(__('app.actions.mark_as_inactive'))
                            ->requiresConfirmation()
                            ->action(fn (Member $record) => tap($record, function ($record) {
                                $record->update(['status' => 'inactive']);
                                Notification::make()
                                    ->title(__('app.notifications.member_deactivated'))
                                    ->danger()
                                    ->send();
                            }))
                            ->visible(fn ($record) => $record->status->value === 'active'),
                        self::banAction(),
                        self::unbanAction(),
                    ])->dropdown(false),
                    ActionGroup::make([
                        Action::make('heading_actions')
                            ->label(__('app.actions.record_actions'))
                            ->disabled()
                            ->color('gray'),
                        ViewAction::make(),
                        EditAction::make()->hiddenLabel(),
                        DeleteAction::make()
                            ->hiddenLabel()
                            ->using(fn (Member $record): bool => $record->forceDelete()),
                    ])->dropdown(false),
                ]),
            ])->recordUrl(fn ($record): string => route('filament.admin.resources.members.view', $record->id))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('ban')
                        ->authorize(fn (): bool => Gate::allows('banAny', Member::class))
                        ->color('danger')
                        ->icon('heroicon-m-no-symbol')
                        ->label(__('app.members.ban_action'))
                        ->requiresConfirmation()
                        ->modalHeading(__('app.members.ban_confirm_title'))
                        ->modalDescription(__('app.members.ban_confirm_body'))
                        ->modalSubmitActionLabel(__('app.members.ban_action'))
                        ->schema([
                            Textarea::make('reason')
                                ->label(__('app.members.ban_reason'))
                                ->placeholder(__('app.members.ban_reason_optional'))
                                ->maxLength(500)
                                ->rows(3)
                                ->columnSpanFull(),
                        ])
                        ->action(function (EloquentCollection $records, array $data): void {
                            Gate::authorize('banAny', Member::class);

                            $records->each(function (Member $record) use ($data): void {
                                if ($record->status?->value === 'banned') {
                                    return;
                                }

                                Gate::authorize('ban', $record);

                                DB::transaction(function () use ($record, $data): void {
                                    $record->update([
                                        'status' => 'banned',
                                        'ban_reason' => filled($data['reason'] ?? null) ? $data['reason'] : null,
                                    ]);
                                });

                                MemberBanChanged::dispatch(
                                    memberId: (int) $record->id,
                                    banned: true,
                                    locationTokens: LocationToken::query()->pluck('token')->all(),
                                );
                            });

                            Notification::make()
                                ->title(__('app.members.banned_toast'))
                                ->danger()
                                ->send();
                        }),
                    DeleteBulkAction::make()
                        ->using(function (DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records): void {
                            $records->each(static function (Member $record) use ($action): void {
                                try {
                                    $record->forceDelete() || $action->reportBulkProcessingFailure();
                                } catch (Throwable $exception) {
                                    $action->reportBulkProcessingFailure();

                                    report($exception);
                                }
                            });
                        }),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
