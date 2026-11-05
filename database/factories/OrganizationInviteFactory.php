<?php

namespace Database\Factories;

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationInvite>
 */
class OrganizationInviteFactory extends Factory
{
    protected $model = OrganizationInvite::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => OrganizationInvite::hashToken(OrganizationInvite::generateToken()),
            'role' => OrganizationRole::Member,
            'status' => InviteStatus::Pending,
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }

    /** Store the hash of a known plain token, so a test can open the accept link. */
    public function withToken(string $token): static
    {
        return $this->state(fn () => [
            'token_hash' => OrganizationInvite::hashToken($token),
        ]);
    }

    /** Mark the invite as already accepted. */
    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => InviteStatus::Accepted,
        ]);
    }

    /** Mark the invite as expired (past expiry + expired status). */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => InviteStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }

    /** Mark the invite as revoked. */
    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => InviteStatus::Revoked,
        ]);
    }

    /** Set the invite role to admin. */
    public function asAdmin(): static
    {
        return $this->state(fn () => [
            'role' => OrganizationRole::Admin,
        ]);
    }

    /** Set the invite role to viewer. */
    public function asViewer(): static
    {
        return $this->state(fn () => [
            'role' => OrganizationRole::Viewer,
        ]);
    }
}
