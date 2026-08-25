<?php

namespace App\Models;

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Concerns\CascadesSoftDeletes;
use App\Models\Concerns\ScopedByLocation;
use App\Support\AppConfig;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $photo
 * @property string $code
 * @property string $name
 * @property string|null $email
 * @property string|null $contact
 * @property string|null $emergency_contact
 * @property string|null $health_issue
 * @property string|null $gender
 * @property Carbon|null $dob
 * @property string|null $address
 * @property string|null $country
 * @property string|null $state
 * @property string|null $city
 * @property string|null $pincode
 * @property string|null $source
 * @property string|null $goal
 * @property Status|null $status
 * @property-read Collection<int, Subscription> $subscriptions
 * @property-read Collection<int, PlanCheckIn> $checkIns
 * @property-read Location|null $currentLocation
 */
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use CascadesSoftDeletes, HasFactory, ScopedByLocation, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'photo',
        'code',
        'name',
        'email',
        'contact',
        'emergency_contact',
        'health_issue',
        'gender',
        'dob',
        'government_id',
        'address',
        'country',
        'state',
        'city',
        'pincode',
        'source',
        'goal',
        'status',
        'ban_reason',
    ];

    protected $casts = ['dob' => 'date', 'status' => Status::class];

    /**
     * The attributes that should be mutated to dates.
     * (SoftDeletes already adds deleted_at rollover.)
     *
     * @var list<string>
     */
    protected $dates = [
        'dob',
        'deleted_at',
    ];

    /**
     * Get the subscriptions for the member.
     */
    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Whether the member holds at least one date-valid subscription
     * (ongoing/expiring, started, not ended) in the app timezone. Pure
     * membership signal — no plan-availability or location filtering, those
     * stay in PlanCheckInService::eligibleSubscriptions().
     */
    public function hasOngoingSubscription(): bool
    {
        $today = Carbon::today(AppConfig::timezone())->toDateString();

        return $this->subscriptions()
            ->whereIn('status', [Status::Ongoing->value, Status::Expiring->value])
            ->whereDate('start_date', '<=', $today)
            ->where(fn ($query) => $query
                ->whereNull('end_date')
                ->orWhereDate('end_date', '>=', $today))
            ->exists();
    }

    /**
     * The single hard check-in blocker, or null when the member may proceed
     * to the normal eligibility flow. Banned is the only unconditional gate;
     * subscription problems route to the renewal / payment flows instead.
     */
    public function checkInBlocker(): ?string
    {
        return $this->status === Status::Banned ? 'banned' : null;
    }

    /**
     * The jurisdiction location of the member's most recent subscription.
     *
     * The member's location is not stored — it is derived from the plan of
     * the member's latest subscription. `null` means an "All Locations" plan
     * (or no subscriptions yet), i.e. the member is visible and check-in-able
     * at every location.
     */
    public function currentLocation(): ?Location
    {
        return $this->subscriptions()
            ->whereHas('plan.location')
            ->with('plan.location')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first()
            ?->plan
            ?->location;
    }

    /**
     * Location scoping for members: the member's location is never stored or
     * auto-assigned — it is derived from the plan of the member's
     * subscriptions (a plan with no location means an "All Locations"
     * jurisdiction that is visible to every location-scoped account).
     * Members without any subscription yet have no jurisdiction and stay
     * visible to every location-scoped account, like legacy records.
     */
    protected static function bootScopedByLocation(): void
    {
        static::addGlobalScope('location', function (Builder $builder): void {
            $locationIds = self::currentLocationIds();

            if ($locationIds === null) {
                return;
            }

            $builder->where(function (Builder $query) use ($locationIds): void {
                $query->whereHas('subscriptions.plan', function (Builder $plan) use ($locationIds): void {
                    $plan->whereIn('plans.location_id', $locationIds)
                        ->orWhereNull('plans.location_id');
                })->orWhereDoesntHave('subscriptions', function (Builder $query): void {
                    $query->withoutGlobalScopes();
                });
            });
        });
    }

    /**
     * Find a member that is a duplicate of the given identifiers: the
     * member's name, contact, government ID and email must ALL match the
     * submitted values (empty values only match empty values). `contact`
     * may be an array of accepted values, e.g. the normalized and raw
     * phone forms.
     *
     * @param  array{name?: string|null, contact?: string|list<string>|null, government_id?: string|null, email?: string|null}  $identifiers
     */
    public static function findDuplicateByIdentifiers(array $identifiers): ?self
    {
        $name = $identifiers['name'] ?? null;
        $contact = $identifiers['contact'] ?? null;
        $governmentId = $identifiers['government_id'] ?? null;

        if (blank($name) && blank($contact) && blank($governmentId)) {
            return null;
        }

        $contactValues = array_values(array_filter((array) $contact, fn ($value): bool => filled($value)));

        return static::query()
            ->where(function (Builder $query) use ($name): void {
                filled($name)
                    ? $query->where('name', $name)
                    : $query->whereNull('name');
            })
            ->where(function (Builder $query) use ($contactValues): void {
                if (empty($contactValues)) {
                    $query->whereNull('contact');

                    return;
                }

                $query->whereIn('contact', $contactValues);
            })
            ->where(function (Builder $query) use ($governmentId): void {
                filled($governmentId)
                    ? $query->where('government_id', $governmentId)
                    : $query->whereNull('government_id');
            })
            ->first();
    }

    /**
     * Shared identifier search for staff-facing member pickers and the
     * reception walk-up check-in: matches name, member code, government
     * ID and contact. Contact matches the raw term plus its normalized
     * phone form, so a number typed without the country code still finds
     * members stored with one. Queries run through the location global
     * scope (accessible locations / current TenantContext location).
     *
     * @return Collection<int, self>
     */
    public static function searchByIdentifier(string $term, int $limit = 50): Collection
    {
        $term = trim($term);
        $normalizedPhone = Helpers::normalizePhone($term);

        return static::query()
            ->where(function (Builder $query) use ($term, $normalizedPhone): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('government_id', 'like', "%{$term}%")
                    ->orWhere('contact', 'like', "%{$term}%")
                    ->when(
                        filled($normalizedPhone) && $normalizedPhone !== $term,
                        fn (Builder $phoneQuery): Builder => $phoneQuery->orWhere('contact', $normalizedPhone),
                    );
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return HasMany<PlanCheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(PlanCheckIn::class);
    }

    /**
     * Boot the model and add cascade delete and restore behavior.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $member): void {
            if (! $member->code) {
                $member->code = Helpers::generateLastNumber('member', Member::class, null, 'code');
            }
        });
    }

    /**
     * Relationship method names to cascade when deleting/restoring.
     *
     * @return list<string>
     */
    protected static function relationsToCascade(): array
    {
        return ['subscriptions'];
    }
}
