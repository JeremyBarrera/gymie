<?php

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Filament\Resources\Plans\Schemas\PlanForm;
use App\Filament\Resources\Plans\Schemas\PlanInfolist;
use App\Filament\Resources\Plans\Tables\PlanTable;
use App\Helpers\Helpers;
use App\Models\Plan;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('app.resources.plans.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.resources.plans.plural');
    }

    public static function getNavigationLabel(): string
    {
        return static::getPluralModelLabel();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'code',
            'services.name',
        ];
    }

    

    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        $query->with(['services']);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        
        $details = [];

        if (filled($record->code)) {
            $details[__('app.fields.code')] = $record->code;
        }

        if ($record->services->isNotEmpty()) {
            $details[__('app.fields.service')] = $record->services->map->name->implode(', ');
        }

        if (! is_null($record->amount)) {
            $details[__('app.fields.amount')] = Helpers::formatCurrency((float) $record->amount);
        }

        if (! is_null($record->days)) {
            $details[__('app.fields.days')] = (string) $record->days;
        }

        return $details;
    }

    

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema);
    }

    

    public static function table(Table $table): Table
    {
        return PlanTable::configure($table);
    }

    

    public static function infolist(Schema $schema): Schema
    {
        return PlanInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
        ];
    }
}
