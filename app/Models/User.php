<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OrganizationRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /** @var array<int, string>|null The role of the user by organization ID, once read by `roleIn()`. */
    private ?array $organizationRoles = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'onboarding_completed',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'onboarding_completed' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The role of the user in an organization, or null when the user is not a member. All the roles of the user are read
     * with the first call and kept on the instance, as the policies ask once per table row.
     */
    public function roleIn(int $organizationId): ?OrganizationRole
    {
        $this->organizationRoles ??= DB::table('organization_user')
            ->where('user_id', $this->getKey())
            ->pluck('role', 'organization_id')
            ->all();

        $role = $this->organizationRoles[$organizationId] ?? null;

        return $role === null ? null : OrganizationRole::tryFrom($role);
    }

    /** Forgets the roles kept by `roleIn()`, after they were changed. */
    public function forgetRoles(): void
    {
        $this->organizationRoles = null;
    }

    public function createdDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'created_by');
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->organizations;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->organizations()->whereKey($tenant)->exists();
    }

    public function personalOrganization(): ?Organization
    {
        return $this->organizations()
            ->where('personal_team', true)
            ->where('created_by', $this->id)
            ->first();
    }

    public function createPersonalOrganization(?string $timezone = null): Organization
    {
        $name = filled($this->name)
            ? $this->name."'s Workspace"
            : 'Personal Workspace';

        $slug = Str::limit(Str::slug($name), 240, '').'-'.Str::random(6);

        $organization = Organization::create([
            'name' => Str::limit($name, 255, ''),
            'slug' => $slug,
            'personal_team' => true,
            'created_by' => $this->id,
            'timezone' => Organization::validTimezoneOrUtc($timezone),
        ]);

        $this->organizations()->attach($organization, [
            'role' => OrganizationRole::Owner->value,
        ]);

        return $organization;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
