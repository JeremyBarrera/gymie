<?php

namespace App\Filament\Resources\Plans\Schemas;

use App\Enums\Status;
use App\Filament\Resources\Services\Schemas\ServiceForm;
use App\Helpers\Helpers;
use App\Models\Service;
use App\Support\Data;
use App\Support\Locations\LocationAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class PlanForm
{
    /**
     * Configure the plan form schema.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Fieldset::make()
                    ->label(function (Get $get): HtmlString {
                        $rawStatus = $get('status');
                        $status = Status::tryFrom(Data::string($rawStatus, Status::Active->value)) ?? Status::Active;
                        $html = Blade::render(
                            '<x-filament::badge class="inline-flex ml-2" :color="$color">
                                {{ $label }}
                            </x-filament::badge>',
                            [
                                'color' => $status->getColor(),
                                'label' => $status->getLabel(),
                            ]
                        );

                        return new HtmlString($html);
                    })
                    ->schema([
                        TextInput::make('name')
                            ->label(__('app.fields.name'))
                            ->placeholder(__('app.placeholders.plan_name'))
                            ->unique(ignoreRecord: true)
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('code')
                            ->placeholder(__('app.placeholders.plan_code'))
                            ->label(__('app.fields.code'))
                            ->unique(ignoreRecord: true)
                            ->required(),
                        Select::make('location_id')
                            ->label(__('app.fields.location'))
                            ->options(fn (): array => LocationAccess::locationOptions(Auth::user()))
                            ->placeholder(__('app.options.all_locations'))
                            ->helperText(__('app.helpers.all_locations'))
                            ->default(fn (): ?int => LocationAccess::firstAccessibleLocationId(Auth::user()))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->columnSpan(2),
                        Select::make('services')
                            ->label(__('app.fields.service'))
                            ->relationship(name: 'services', titleAttribute: 'name', modifyQueryUsing: function (Builder $query, Get $get): void {
                                $locationId = $get('location_id');

                                if (filled($locationId)) {
                                    $query->where('location_id', $locationId);
                                }
                            })
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->placeholder(__('app.placeholders.select_services'))
                            ->default(fn (Get $get): array => Service::query()
                                ->when(
                                    filled($get('location_id')),
                                    fn (Builder $query, int $locationId): Builder => $query->where('location_id', $locationId),
                                )
                                ->orderBy('name')
                                ->limit(1)
                                ->pluck('id')
                                ->all())
                            ->required()
                            ->createOptionModalHeading(__('app.actions.new', ['resource' => __('app.resources.services.singular')]))
                            ->createOptionForm(fn (Schema $schema): Schema => ServiceForm::configure($schema))
                            ->createOptionAction(fn (Action $action): Action => $action
                                ->authorize(fn (): bool => Gate::allows('create', Service::class))
                                ->extraModalFooterActions([]))
                            ->createOptionUsing(function (array $data, Get $get): int {
                                Gate::authorize('create', Service::class);

                                return Data::int(Service::query()->create([
                                    ...$data,
                                    'location_id' => $data['location_id'] ?? $get('location_id'),
                                ])->getKey());
                            })
                            ->columnSpan(2),
                        Toggle::make('track_uses')
                            ->label(__('app.fields.track_uses'))
                            ->helperText(__('app.helpers.track_uses'))
                            ->live()
                            ->default(false)
                            ->columnSpan(1),
                        TextInput::make('days')
                            ->placeholder(__('app.placeholders.plan_days'))
                            ->numeric()
                            ->minValue(1)
                            ->label(__('app.fields.days'))
                            ->helperText(__('app.helpers.plan_days_optional'))
                            ->extraAttributes(['class' => 'verify-money-input'])
                            ->columnSpan(1),
                        TextInput::make('uses_limit')
                            ->label(__('app.fields.uses_limit'))
                            ->numeric()
                            ->minValue(1)
                            ->placeholder(__('app.placeholders.uses_limit'))
                            ->required(fn (Get $get): bool => (bool) $get('track_uses'))
                            ->visible(fn (Get $get): bool => (bool) $get('track_uses'))
                            ->extraAttributes(['class' => 'verify-money-input'])
                            ->columnSpan(1),
                        TextInput::make('amount')
                            ->placeholder(__('app.placeholders.plan_amount'))
                            ->numeric()
                            ->prefix(Helpers::getCurrencySymbol())
                            ->label(__('app.fields.amount'))
                            ->required()
                            ->extraAttributes(['class' => 'verify-money-input'])
                            ->columnSpanFull(),
                        TextInput::make('description')
                            ->placeholder(__('app.placeholders.plan_description'))
                            ->label(__('app.fields.description'))
                            ->columnSpanFull(),
                    ])->columns(3),
            ]);
    }
}
