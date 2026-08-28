<?php

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\Subscriptions\Pages\CreateSubscription;
use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionForm;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionInfolist;
use App\Filament\Resources\Subscriptions\Tables\SubscriptionTable;
use App\Models\Subscription;
use App\Support\Dates\DeviceDateFormat;
use App\Support\Filament\GlobalSearchBadge;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    public static function getModelLabel(): string
    {
        return __('app.resources.subscriptions.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.resources.subscriptions.plural');
    }

    public static function getNavigationLabel(): string
    {
        return static::getPluralModelLabel();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'member.name',
            'member.code',
            'member.email',
            'member.contact',
            'plan.name',
            'plan.code',
        ];
    }

    

    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        $query->with(['member', 'plan']);
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        assert($record instanceof Subscription);
        $title = trim(implode(' — ', array_filter([
            $record->member?->name,
            $record->plan?->name,
        ], fn (?string $value): bool => filled($value))));

        return filled($title) ? $title : static::getModelLabel();
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        assert($record instanceof Subscription);
        $details = [];

        if ($record->start_date) {
            $details[__('app.fields.start_date')] = $record->start_date->translatedFormat(DeviceDateFormat::date());
        }

        if ($record->end_date) {
            $details[__('app.fields.end_date')] = $record->end_date->translatedFormat(DeviceDateFormat::date());
        }

        if ($record->status) {
            $details[__('app.fields.status')] = GlobalSearchBadge::status($record->status);
        }

        return $details;
    }

    

    public static function form(Schema $schema): Schema
    {
        return SubscriptionForm::configure($schema);
    }

    

    public static function table(Table $table): Table
    {
        return SubscriptionTable::configure($table);
    }

    

    public static function infolist(Schema $schema): Schema
    {
        return SubscriptionInfolist::configure($schema);
    }

    

    public static function getRelations(): array
    {
        return [
            InvoicesRelationManager::class,
        ];
    }

    

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'create' => CreateSubscription::route('/create'),
            'view' => ViewSubscription::route('/{record}'),
            'edit' => EditSubscription::route('/{record}/edit'),
        ];
    }

    

    public static function getEloquentQuery(): Builder
    {
        
        
        return parent::getEloquentQuery()->whereHas('member');
    }
}
