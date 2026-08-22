<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Models\PlanCheckIn;
use App\Support\AppConfig;
use App\Support\Dates\DeviceDateFormat;
use Carbon\Carbon;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlanCheckInsRelationManager extends RelationManager
{
    protected static string $relationship = 'checkIns';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('app.resources.check_ins.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $since = Carbon::today(AppConfig::timezone())->subDays(30)->startOfDay();

        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['plan', 'service', 'location', 'checkedInBy'])
                ->where('checked_in_at', '>=', $since)
                ->latest('checked_in_at'))
            ->columns([
                TextColumn::make('checked_in_at')
                    ->label(__('app.fields.checked_in_at'))
                    ->dateTime(DeviceDateFormat::dateTime()),
                TextColumn::make('plan.name')
                    ->label(__('app.resources.plans.singular'))
                    ->description(fn (PlanCheckIn $record): ?string => $record->plan?->code),
                TextColumn::make('service.name')
                    ->label(__('app.fields.service')),
                TextColumn::make('location.name')
                    ->label(__('app.fields.location'))
                    ->placeholder(__('app.placeholders.dash')),
                TextColumn::make('checkedInBy.name')
                    ->label(__('app.fields.checked_in_by'))
                    ->placeholder(__('app.placeholders.dash')),
            ])
            ->defaultSort('checked_in_at', 'desc');
    }
}
