<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Location;
use App\Models\User;
use App\Support\Locations\LocationAccess;
use Filament\Actions\Action;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return __('app.actions.edit', ['resource' => UserResource::getModelLabel()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
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
            RestoreAction::make(),
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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $locationIds = array_map('intval', $data['locations'] ?? []);

        LocationAccess::assertAssignmentsWithinJurisdiction(
            Auth::user(),
            $data['role'] !== null ? [(int) $data['role']] : [],
            $locationIds,
        );

        $foundingLocationIds = Location::query()
            ->where('founding_admin_user_id', $this->record->id)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($foundingLocationIds as $foundingLocationId) {
            if (! in_array($foundingLocationId, $locationIds, true)) {
                throw ValidationException::withMessages([
                    'locations' => [__('app.validation.founding_location_required')],
                ]);
            }
        }

        return $data;
    }

    private function hasOtherOwners(): bool
    {
        return User::query()
            ->where('id', '!=', $this->record->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
            ->exists();
    }
}
