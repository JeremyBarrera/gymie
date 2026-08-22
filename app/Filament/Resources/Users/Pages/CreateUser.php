<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\Locations\LocationAccess;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return __('app.actions.new', ['resource' => UserResource::getModelLabel()]);
    }

    public function getBreadcrumbs(): array
    {
        return [
            __('app.navigation.groups.administration'),
            UserResource::getUrl('index') => UserResource::getNavigationLabel(),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        LocationAccess::assertAssignmentsWithinJurisdiction(
            Auth::user(),
            $data['role'] !== null ? [(int) $data['role']] : [],
            $data['locations'] ?? [],
        );

        return $data;
    }
}
