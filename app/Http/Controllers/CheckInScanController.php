<?php

namespace App\Http\Controllers;

use App\Enums\Status;
use App\Events\QueueEntryCreated;
use App\Helpers\Helpers;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\QueueEntry;
use App\Services\Membership\PlanCheckInService;
use App\Support\DevOps\FeatureFlags;
use App\Support\ThemeColor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CheckInScanController extends Controller
{
    public function __construct(
        private PlanCheckInService $planCheckInService
    ) {}

    public function showCheckIn(string $token)
    {
        return $this->showScanPage($token, 'checkin');
    }

    public function showSignUp(string $token)
    {
        return $this->showScanPage($token, 'signup');
    }

    private function showScanPage(string $token, string $kind)
    {
        $locationToken = LocationToken::where('token', $token)
            ->where('kind', $kind)
            ->with('tokenable')
            ->first();

        if (! $locationToken || ! $locationToken->tokenable instanceof Location) {
            return $this->showInvalidToken();
        }

        $location = $locationToken->tokenable;
        $themeColor = $location->getEffectiveThemeColor();
        $palette = ThemeColor::from($themeColor)->palette();

        $background = $location->getEffectiveBackgroundColor();
        $accent = $location->getEffectiveAccentColor();

        return response()
            ->view('checkin.scan', compact('location', 'token', 'kind', 'themeColor', 'palette', 'background', 'accent'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /**
     * The scanned token no longer exists (for example the QR code was
     * deleted from the admin panel), so every lookup below fails.
     *
     * Renders a friendly, localized page instead of a bare 404.
     */
    private function showInvalidToken()
    {
        $themeColor = Helpers::getSettings()['general']['theme_color'] ?? '#2563eb';
        $palette = ThemeColor::from($themeColor)->palette();
        $background = $themeColor;
        $accent = $themeColor;

        return response()
            ->view('checkin.invalid-token', compact('palette', 'background', 'accent'), 404);
    }

    /**
     * Neutral "contact front desk" page — shown after 3 failed check-in
     * attempts (client-side redirect) or when the member needs assistance.
     */
    public function contactFrontDesk(Request $request)
    {
        $themeColor = $request->query('theme_color')
            ?? Helpers::getSettings()['general']['theme_color']
            ?? '#2563eb';

        $palette = ThemeColor::from($themeColor)->palette();
        $background = $themeColor;
        $accent = $themeColor;

        return view('checkin.contact-front-desk', compact('palette', 'background', 'accent'));
    }

    public function submit(Request $request): JsonResponse
    {
        $kind = $request->input('kind');

        if ($kind === 'checkin') {
            $validated = $request->validate([
                'kind' => ['required', 'in:checkin,signup'],
                'identifier_type' => ['required', 'in:contact,government_id,code'],
                'value' => ['required', 'string', 'max:255'],
                'token' => ['required', 'string'],
            ]);
        } else {
            $validated = $request->validate([
                'kind' => ['required', 'in:checkin,signup'],
                'token' => ['required', 'string'],
            ]);
        }

        $locationToken = LocationToken::where('token', $validated['token'])
            ->where('kind', $validated['kind'])
            ->with('tokenable')
            ->first();

        if (! $locationToken || ! $locationToken->tokenable instanceof Location) {
            return response()->json([
                'message' => __('app.scan.invalid_token'),
            ], 400);
        }

        $location = $locationToken->tokenable;

        if ($validated['kind'] === 'checkin') {
            return $this->handleCheckIn($request, $location, $validated);
        } else {
            return $this->handleSignUp($request, $location, $validated);
        }
    }

    private function handleCheckIn(Request $request, Location $location, array $validated): JsonResponse
    {
        $identifierType = $validated['identifier_type'];
        $value = $validated['value'];

        $matches = match ($identifierType) {
            'contact' => $this->lookupMembersByContact($value),
            'government_id' => Member::where('government_id', $value)->orderBy('id')->get(),
            'code' => Member::where('code', $value)->orderBy('id')->get(),
        };

        if ($matches->isEmpty()) {
            return response()->json([
                'match' => false,
            ]);
        }

        $activeMatches = $matches->filter(fn (Member $member): bool => $member->status === Status::Active)
            ->values();

        if ($activeMatches->isEmpty()) {
            return response()->json([
                'match' => false,
                'message' => __('app.reception.check_in_member_inactive'),
            ]);
        }

        if ($activeMatches->count() > 1) {
            return $this->createAmbiguousCheckIn($location, $activeMatches, $identifierType, $value);
        }

        $member = $activeMatches->first();

        $eligibleSubscriptions = $this->planCheckInService->eligibleSubscriptions($member)
            ->map(fn ($sub) => [
                'id' => $sub->id,
                'label' => $this->planCheckInService->subscriptionOptionLabel($sub),
                'plan_id' => $sub->plan_id,
                'service_id' => $sub->plan?->service_id,
                'uses_remaining' => $this->planCheckInService->remainingUses($sub),
            ])
            ->values()
            ->all();

        if (empty($eligibleSubscriptions)) {
            return response()->json([
                'match' => false,
                'message' => __('app.reception.check_in_not_eligible'),
            ]);
        }

        // Get location token for this location (checkin kind)
        $locationToken = LocationToken::where('tokenable_type', Location::class)
            ->where('tokenable_id', $location->id)
            ->where('kind', 'checkin')
            ->first();

        if (! $locationToken) {
            return response()->json([
                'match' => false,
                'message' => __('app.scan.checkin_not_configured'),
            ]);
        }

        // Create queue entry with first eligible subscription as payload
        $queueEntry = QueueEntry::create([
            'uuid' => Str::uuid(),
            'location_id' => $location->id,
            'kind' => 'checkin',
            'payload' => [
                'member_id' => $member->id,
                'subscription_id' => $eligibleSubscriptions[0]['id'],
                'identifier_type' => $identifierType,
                'identifier_value' => $value,
            ],
            'identifier_type' => $identifierType,
            'status' => 'waiting',
            'expires_at' => now()->addMinutes(10),
        ]);

        // Schedule auto-expiration at expires_at (event-driven, no polling)
        if (! app()->environment('testing')) {
            \App\Jobs\ExpireQueueEntry::dispatch(
                $queueEntry->id,
                $queueEntry->uuid,
                $locationToken->token
            )->delay($queueEntry->expires_at);
        }

        // Broadcast QueueEntryCreated event
        $position = $queueEntry->position();

        event(new QueueEntryCreated(
            $queueEntry->id,
            $queueEntry->uuid,
            $locationToken->token,
            'checkin',
            $queueEntry->payload,
            null,
            $position
        ));

        return response()->json([
            'match' => true,
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'photo' => $member->photo,
                'status' => $member->status?->value,
                'eligible' => $eligibleSubscriptions,
            ],
            'queue_entry_uuid' => $queueEntry->uuid,
        ]);
    }

    private function handleSignUp(Request $request, Location $location, array $validated): JsonResponse
    {
        if (! FeatureFlags::activeForUser(Auth::user(), 'api.signup.apply')) {
            return response()->json([
                'message' => __('app.api.feature_disabled'),
            ], 403);
        }

        $signupData = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:50'],
            'government_id' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'gender' => ['required', 'string', 'in:male,female,other'],
            'dob' => ['required', 'date'],
            'emergency_contact' => ['nullable', 'string', 'max:50'],
            'health_issue' => ['nullable', 'string', 'max:500'],
            'goal' => ['nullable', 'string', 'max:100'],
        ]);

        $rawContact = $signupData['contact'];
        $signupData['contact'] = Helpers::normalizePhone($signupData['contact']) ?? $signupData['contact'];

        if (isset($signupData['emergency_contact'])) {
            $signupData['emergency_contact'] = Helpers::normalizePhone($signupData['emergency_contact']);
        }

        $existingMember = Member::findDuplicateByIdentifiers([
            'name' => $signupData['name'],
            'contact' => [$signupData['contact'], $rawContact],
            'government_id' => $signupData['government_id'],
        ]);

        if ($existingMember) {
            return response()->json([
                'match' => false,
                'message' => __('app.scan.member_already_exists'),
            ]);
        }

        $locationToken = LocationToken::where('tokenable_type', Location::class)
            ->where('tokenable_id', $location->id)
            ->where('kind', 'signup')
            ->first();

        if (! $locationToken) {
            return response()->json([
                'match' => false,
                'message' => __('app.scan.location_not_configured'),
            ]);
        }

        $payload = array_merge(
            array_intersect_key($signupData, array_flip([
                'name', 'contact', 'government_id', 'email', 'gender', 'dob',
                'emergency_contact', 'health_issue', 'goal',
            ])),
            ['location_id' => $location->id]
        );

        $queueEntry = QueueEntry::create([
            'uuid' => Str::uuid(),
            'location_id' => $location->id,
            'kind' => 'signup',
            'payload' => $payload,
            'identifier_type' => 'contact',
            'status' => 'waiting',
            'expires_at' => now()->addMinutes(10),
        ]);

        // Schedule auto-expiration at expires_at (event-driven, no polling)
        if (! app()->environment('testing')) {
            \App\Jobs\ExpireQueueEntry::dispatch(
                $queueEntry->id,
                $queueEntry->uuid,
                $locationToken->token
            )->delay($queueEntry->expires_at);
        }

        $position = $queueEntry->position();

        event(new QueueEntryCreated(
            $queueEntry->id,
            $queueEntry->uuid,
            $locationToken->token,
            'signup',
            $queueEntry->payload,
            null,
            $position
        ));

        return response()->json([
            'match' => true,
            'queue_entry_uuid' => $queueEntry->uuid,
        ]);
    }

    /**
     * Look up every member by contact, matching either the normalized form
     * (with country code) or the raw submitted value so both legacy and
     * newly-stored numbers resolve. Returns every match so the staff can
     * pick the correct profile when a number is shared.
     *
     * @return Collection<int, Member>
     */
    private function lookupMembersByContact(string $value): Collection
    {
        $normalized = Helpers::normalizePhone($value);
        $candidates = array_values(array_unique(array_filter([$normalized, $value])));

        return Member::whereIn('contact', $candidates)->orderBy('id')->get();
    }

    /**
     * Create a check-in queue entry when an identifier matches more than one
     * active member. The payload carries every candidate id; staff resolves
     * the correct profile in the live popup before approving.
     *
     * @param  Collection<int, Member>  $members
     */
    private function createAmbiguousCheckIn(Location $location, Collection $members, string $identifierType, string $value): JsonResponse
    {
        $locationToken = LocationToken::where('tokenable_type', Location::class)
            ->where('tokenable_id', $location->id)
            ->where('kind', 'checkin')
            ->first();

        if (! $locationToken) {
            return response()->json([
                'match' => false,
                'message' => __('app.scan.checkin_not_configured'),
            ]);
        }

        $queueEntry = QueueEntry::create([
            'uuid' => Str::uuid(),
            'location_id' => $location->id,
            'kind' => 'checkin',
            'payload' => [
                'candidate_member_ids' => $members->pluck('id')->values()->all(),
                'identifier_type' => $identifierType,
                'identifier_value' => $value,
            ],
            'identifier_type' => $identifierType,
            'status' => 'waiting',
            'expires_at' => now()->addMinutes(10),
        ]);

        // Schedule auto-expiration at expires_at (event-driven, no polling)
        if (! app()->environment('testing')) {
            \App\Jobs\ExpireQueueEntry::dispatch(
                $queueEntry->id,
                $queueEntry->uuid,
                $locationToken->token
            )->delay($queueEntry->expires_at);
        }

        $position = $queueEntry->position();

        event(new QueueEntryCreated(
            $queueEntry->id,
            $queueEntry->uuid,
            $locationToken->token,
            'checkin',
            $queueEntry->payload,
            null,
            $position
        ));

        return response()->json([
            'match' => true,
            'members' => $members->map(fn (Member $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'photo' => $member->photo,
                'code' => $member->code,
            ])->values()->all(),
            'queue_entry_uuid' => $queueEntry->uuid,
        ]);
    }

    public function waiting(string $uuid)
    {
        $queueEntry = QueueEntry::where('uuid', $uuid)
            ->with('location')
            ->first();

        if (! $queueEntry) {
            abort(404, 'Queue entry not found');
        }

        $location = $queueEntry->location;
        $themeColor = $location->getEffectiveThemeColor();
        $kind = $queueEntry->kind;
        $palette = ThemeColor::from($themeColor)->palette();
        $background = $location->getEffectiveBackgroundColor();
        $accent = $location->getEffectiveAccentColor();
        $locationToken = $location->tokens()->value('token');

        $memberName = $kind === 'signup' ? ($queueEntry->payload['name'] ?? null) : null;
        $signupToken = $kind === 'signup'
            ? $location->tokens()->where('kind', 'signup')->value('token')
            : null;

        return view('checkin.waiting', compact(
            'queueEntry', 'location', 'themeColor', 'palette', 'uuid',
            'kind', 'background', 'accent', 'locationToken',
            'memberName', 'signupToken',
        ));
    }
}
