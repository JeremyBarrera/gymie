<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('app.resources.subscriptions.plural');
    }

    

    public function isReadOnly(): bool
    {
        return false;
    }

    

    public function form(Schema $schema): Schema
    {
        return SubscriptionResource::form($schema);
    }

    

    public function table(Table $table): Table
    {
        return SubscriptionResource::table($table)
            ->headerActions([
                CreateAction::make()
                    ->icon('heroicon-s-plus')
                    ->modalHeading(__('app.actions.new', ['resource' => __('app.resources.subscriptions.singular')]))
                    ->modalWidth('6xl')
                    ->closeModalByClickingAway(false)
                    ->createAnother(false),
            ]);
    }
}
