<?php

namespace App\Models;

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrganizationInvite extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationInviteFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'email',
        'token',
        'role',
        'status',
        'invited_by',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InviteStatus::class,
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    /** The organization this invite belongs to. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The user who sent this invitation. */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    // ──────────────────────────────────────────────
    // State checks
    // ──────────────────────────────────────────────

    /** Whether this invite is still pending and within its expiry window. */
    public function isValid(): bool
    {
        return $this->status === InviteStatus::Pending
            && $this->expires_at->isFuture();
    }

    /** Whether this invite has passed its expiry date. */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    // ──────────────────────────────────────────────
    // Actions
    // ──────────────────────────────────────────────

    /**
     * Mark this invite as accepted and return the updated model.
     * Should be called inside a DB transaction alongside membership creation.
     */
    public function markAccepted(): self
    {
        $this->update(['status' => InviteStatus::Accepted]);

        return $this;
    }

    /**
     * Revoke this invite so the token can no longer be used.
     * Only pending invites can be revoked.
     */
    public function revoke(): self
    {
        $this->update(['status' => InviteStatus::Revoked]);

        return $this;
    }

    /**
     * Generate a new cryptographically-secure token and reset the expiry.
     * Used when resending an invitation.
     */
    public function regenerateToken(int $expiryDays = 7): self
    {
        $this->update([
            'token' => static::generateToken(),
            'expires_at' => now()->addDays($expiryDays),
        ]);

        return $this;
    }

    // ──────────────────────────────────────────────
    // Static helpers
    // ──────────────────────────────────────────────

    /** Generate a unique, unguessable 64-character token. */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * Build the full accept URL for a given token.
     * This is the link included in the invitation email.
     */
    public static function acceptUrl(string $token): string
    {
        return url("/invite/accept/{$token}");
    }
}
