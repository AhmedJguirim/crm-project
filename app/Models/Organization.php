<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'logo_path',
        'personal_team',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function getMemberRole(User|int $user): ?OrganizationRole
    {
        $member = $this->members()->where('user_id', $user instanceof User ? $user->id : $user)->first();

        return $member ? OrganizationRole::from($member->pivot->role) : null;
    }

    public function isOwner(User|int $user): bool
    {
        return $this->getMemberRole($user) === OrganizationRole::Owner;
    }

    public function isAdminOrOwner(User $user): bool
    {
        $role = $this->getMemberRole($user);

        return $role === OrganizationRole::Owner || $role === OrganizationRole::Admin;
    }

    /** Check whether the given user is a member of this organization. */
    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    /** All invitations (any status) sent for this organization. */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvite::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Check if a pending (non-expired) invite already exists for the given email.
     * Used to prevent duplicate invitations.
     */
    public function hasPendingInviteFor(string $email): bool
    {
        return $this->invitations()
            ->where('email', $email)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
