<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    

    protected function requirePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission) === true, 403);
    }

    

    protected function noContent(): JsonResponse
    {
        return response()->json([], 204);
    }

    

    protected function deleteModel(Request $request, string $permission, Model $record): JsonResponse
    {
        $this->requirePermission($request, $permission);

        $record->delete();

        return $this->noContent();
    }

    

    protected function currentUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    

    protected function restoreSoftDeleted(Request $request, string $permission, string $modelClass, int $id): Model
    {
        $this->requirePermission($request, $permission);

        abort_unless(in_array(SoftDeletes::class, class_uses_recursive($modelClass), true), 404);

        
        $record = $this->softDeletedQuery($modelClass)->findOrFail($id);

        if (method_exists($record, 'restore')) {
            $record->restore();
        }

        return $record;
    }

    

    protected function forceDeleteSoftDeleted(Request $request, string $permission, string $modelClass, int $id): void
    {
        $this->requirePermission($request, $permission);

        abort_unless(in_array(SoftDeletes::class, class_uses_recursive($modelClass), true), 404);

        $record = $this->softDeletedQuery($modelClass)->findOrFail($id);
        $record->forceDelete();
    }

    

    private function softDeletedQuery(string $modelClass): Builder
    {
        
        $query = $modelClass::query()->withoutGlobalScope(SoftDeletingScope::class);

        return $query;
    }
}
