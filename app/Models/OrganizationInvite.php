<?php

namespace App\Models;

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use Database\Factories\OrganizationInviteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrganizationInvite extends Model
{
    /** @use HasFactory<OrganizationInviteFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'email',
        'token_hash',
        'role',
        'status',
        'invited_by',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

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

    /**
     * The organization this invite belongs to.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The user who sent this invitation.
     *
     * @return BelongsTo<User, $this>
     */
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
     * Replace the token with a new one and reset the expiry. Used when resending an invitation.
     * Only the hash of the new token is stored; the returned plain token goes into the mail link.
     */
    public function regenerateToken(int $expiryDays = 7): string
    {
        $token = static::generateToken();

        $this->update([
            'token_hash' => static::hashToken($token),
            'expires_at' => now()->addDays($expiryDays),
        ]);

        return $token;
    }

    // ──────────────────────────────────────────────
    // Static helpers
    // ──────────────────────────────────────────────

    /** Generate a unique, unguessable 64-character token (plain: it only lives in the mail link). */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /** The SHA-256 hex digest (64 characters) of a plain token: the only form stored in the table. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Find the invite whose stored hash matches the plain token carried by an accept link. */
    public static function findByToken(string $token): ?self
    {
        return static::query()->where('token_hash', static::hashToken($token))->first();
    }

    /**
     * Build the full accept URL for a given plain token.
     * This is the link included in the invitation email; the table only holds the token's hash.
     */
    public static function acceptUrl(string $token): string
    {
        return url("/invite/accept/{$token}");
    }
}
