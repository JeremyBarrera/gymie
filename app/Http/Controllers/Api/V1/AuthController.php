<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Support\Data;
use App\Support\Locations\LocationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->toString())
            ->first();

        if (
            ! $user
            || (! $user->isOwner() && LocationAccess::accessibleLocationIds($user) === [])
            || ! Hash::check(Data::string($request->input('password')), Data::string($user->password))
        ) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $deviceName = Data::string($request->input('device_name')) ?: Data::string($request->userAgent(), 'api');
        $deviceName = mb_substr($deviceName, 0, 255);

        $token = $user->createToken($deviceName)->plainTextToken;

        $user->load('roles');

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    

    public function me(Request $request): UserResource
    {
        
        $user = $request->user();

        $user->load('roles');

        return new UserResource($user);
    }

    

    public function logout(Request $request): JsonResponse
    {
        
        $user = $request->user();

        if ($request->bearerToken() !== null) {
            $user->currentAccessToken()->delete();
        } else {
            $user->tokens()->delete();
        }

        return $this->noContent();
    }
}
