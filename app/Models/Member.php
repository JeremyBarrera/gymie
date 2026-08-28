<?php

namespace App\Models;

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Concerns\CascadesSoftDeletes;
use App\Models\Concerns\ScopedByLocation;
use App\Support\AppConfig;
use App\Support\BlindIndex;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Member extends Model
{
    
    use CascadesSoftDeletes, HasFactory, ScopedByLocation, SoftDeletes;

    

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

    protected $casts = ['dob' => 'date', 'status' => Status::class, 'government_id' => 'encrypted'];

    

    protected $dates = [
        'dob',
        'deleted_at',
    ];

    

    

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    

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

    

    public function checkInBlocker(): ?string
    {
        return $this->status === Status::Banned ? 'banned' : null;
    }

    

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

    

    public function scopeWhereGovernmentId(Builder $query, ?string $value): Builder
    {
        $hash = BlindIndex::compute($value);

        return filled($hash)
            ? $query->where('government_id_hash', $hash)
            : $query->whereNull('government_id_hash');
    }

    

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
                    ? $query->where('government_id_hash', BlindIndex::compute($governmentId))
                    : $query->whereNull('government_id_hash');
            })
            ->first();
    }

    

    public static function searchByIdentifier(string $term, int $limit = 50): Collection
    {
        $term = trim($term);
        $normalizedPhone = Helpers::normalizePhone($term);

        return static::query()
            ->where(function (Builder $query) use ($term, $normalizedPhone): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('contact', 'like', "%{$term}%")
                    ->when(
                        filled($normalizedPhone) && $normalizedPhone !== $term,
                        fn (Builder $phoneQuery): Builder => $phoneQuery->orWhere('contact', $normalizedPhone),
                    )
                    ->orWhere('government_id_hash', BlindIndex::compute($term));
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    

    public function checkIns(): HasMany
    {
        return $this->hasMany(PlanCheckIn::class);
    }

    

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $member): void {
            if (! $member->code) {
                $member->code = Helpers::generateLastNumber('member', Member::class, null, 'code');
            }

            if ($member->isDirty('government_id')) {
                $member->government_id_hash = BlindIndex::compute($member->government_id);
            }
        });

        static::forceDeleted(function (self $member): void {
            Helpers::deleteStoredPhoto($member->photo);
        });
    }

    

    protected static function relationsToCascade(): array
    {
        return ['subscriptions'];
    }
}
