<?php

namespace App\Models;

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Concerns\ScopedByLocation;
use App\Support\Permissions\PermissionFeatureFlags;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    
    use HasApiTokens, HasFactory, HasRoles, Notifiable, ScopedByLocation, SoftDeletes;

    

    protected $fillable = [
        'photo',
        'name',
        'email',
        'status',
        'password',
        'contact',
        'dob',
        'gender',
        'address',
        'country',
        'city',
        'state',
        'pincode',
        'sound_alerts',
    ];

    

    protected $hidden = [
        'password',
        'remember_token',
    ];

    

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'dob' => 'date',
            'status' => Status::class,
            'sound_alerts' => 'boolean',
        ];
    }

    protected $dates = ['deleted_at'];

    

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    

    public function enquiries(): HasMany
    {
        return $this->hasMany(Enquiry::class);
    }

    

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->photo ? Helpers::photoUrl($this->photo) : null;
    }

    

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'user_locations')
            ->withTimestamps();
    }

    

    public function isOwner(): bool
    {
        return $this->hasRole(PermissionFeatureFlags::OWNER_ROLE);
    }

    

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
