<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property-read User $record
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return __('app.titles.record', [
            'resource' => UserResource::getModelLabel(),
            'name' => $this->record->name,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('ownerDeleteWarning')
                ->label(__('app.deletion_prevention.owner_delete_warning'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => $this->record->isOwner() && ! $this->hasOtherOwners())
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->modalHeading(__('app.deletion_prevention.cannot_delete_last_owner'))
                ->modalDescription(__('app.deletion_prevention.cannot_delete_last_owner_description'))
                ->modalCancelAction(false)
                ->modalSubmitAction(false),
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            __('app.navigation.groups.administration'),
            UserResource::getUrl('index') => UserResource::getNavigationLabel(),
            $this->record->name,
        ];
    }

    private function hasOtherOwners(): bool
    {
        return User::query()
            ->where('id', '!=', $this->record->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
            ->exists();
    }
}
