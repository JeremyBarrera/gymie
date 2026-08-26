<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\Helpers;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Services\Membership\PlanCheckInService;
use App\Support\DevOps\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckInController extends ApiController
{
    public function __construct(
        private PlanCheckInService $planCheckInService
    ) {}

    public function lookup(Request $request): JsonResponse
    {
        if (! FeatureFlags::activeForUser(Auth::user(), 'api.checkin.lookup')) {
            return response()->json([
                'message' => __('app.api.feature_disabled'),
            ], 403);
        }

        $validated = $request->validate([
            'identifier_type' => ['required', 'string', 'in:contact,government_id,code'],
            'value' => ['required', 'string', 'max:255'],
            // Optional tenant pin: when present, the member lookup is scoped
            // to the location that owns this location token.
            // When absent, the default location is used (single-tenant fallback).
            'location_token' => ['nullable', 'string'],
        ]);

        $identifierType = $validated['identifier_type'];
        $value = $validated['value'];

        $member = match ($identifierType) {
            'contact' => $this->lookupMemberByContact($value),
            'government_id' => Member::query()->whereGovernmentId($value)->first(),
            'code' => Member::where('code', $value)->first(),
        };

        if (! $member) {
            return response()->json([
                'match' => false,
            ]);
        }

        if ($member->checkInBlocker() !== null) {
            return response()->json([
                'match' => false,
            ]);
        }

        $eligibleSubscriptions = $this->planCheckInService->eligibleSubscriptions($member)
            ->map(fn ($sub) => [
                'id' => $sub->id,
                'label' => $this->planCheckInService->subscriptionOptionLabel($sub),
                'plan_id' => $sub->plan_id,
                'service_id' => $sub->plan?->primaryService()?->id,
                'uses_remaining' => $this->planCheckInService->remainingUses($sub),
            ])
            ->values()
            ->all();

        return response()->json([
            'match' => true,
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'photo' => $member->photo,
                'status' => $member->status?->value,
                'eligible' => $eligibleSubscriptions,
            ],
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        if (! FeatureFlags::activeForUser(Auth::user(), 'api.signup.apply')) {
            return response()->json([
                'message' => __('app.api.feature_disabled'),
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'dob' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'in:male,female,other'],
            'government_id' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'emergency_contact' => ['nullable', 'string', 'max:50'],
            'health_issue' => ['nullable', 'string', 'max:500'],
            'goal' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', 'string', 'max:100'],
            'location_token' => ['required', 'string'],
        ]);

        $locationToken = LocationToken::where('token', $validated['location_token'])
            ->where('kind', 'signup')
            ->first();

        if (! $locationToken) {
            return response()->json([
                'message' => 'Invalid signup token',
            ], 400);
        }

        $validated['contact'] = Helpers::normalizePhone($validated['contact'] ?? null);
        $validated['emergency_contact'] = Helpers::normalizePhone($validated['emergency_contact'] ?? null);

        $identifierType = null;
        $identifierValue = null;

        if ($validated['government_id']) {
            $identifierType = 'government_id';
            $identifierValue = $validated['government_id'];
        } elseif ($validated['contact']) {
            $identifierType = 'contact';
            $identifierValue = $validated['contact'];
        } elseif ($validated['email']) {
            $identifierType = 'email';
            $identifierValue = $validated['email'];
        }

        $duplicateQuery = MemberApplication::query()->where('status', 'pending');

        if ($identifierType) {
            $duplicateQuery->where(function ($query) use ($identifierType, $identifierValue) {
                $query->where('identifier_type', $identifierType)
                    ->where('identifier_value', $identifierValue);
            });
        }

        if ($duplicateQuery->exists()) {
            return response()->json([
                'message' => 'An application with this identifier is already pending',
            ], 409);
        }

        $duplicateMember = Member::findDuplicateByIdentifiers([
            'name' => $validated['name'] ?? null,
            'contact' => $validated['contact'] ?? null,
            'government_id' => $validated['government_id'] ?? null,
        ]);

        if ($duplicateMember) {
            return response()->json([
                'message' => 'A member with this identifier already exists',
            ], 409);
        }

        $payload = array_intersect_key($validated, array_flip([
            'name', 'contact', 'email', 'dob', 'gender', 'government_id',
            'address', 'country', 'state', 'city', 'pincode',
            'emergency_contact', 'health_issue', 'goal', 'source',
        ]));

        DB::transaction(function () use ($identifierType, $identifierValue, $payload) {
            MemberApplication::create([
                'identifier_type' => $identifierType ?? 'unknown',
                'identifier_value' => $identifierValue ?? Str::uuid(),
                'payload' => $payload,
                'status' => 'pending',
            ]);
        });

        return response()->json([
            'message' => 'Application submitted successfully',
        ]);
    }

    /**
     * Look up a member by contact, matching either the normalized form
     * (with country code) or the raw submitted value so both legacy and
     * newly-stored numbers resolve.
     */
    private function lookupMemberByContact(string $value): ?Member
    {
        $normalized = Helpers::normalizePhone($value);
        $candidates = array_values(array_unique(array_filter([$normalized, $value])));

        return Member::whereIn('contact', $candidates)->first();
    }
}
