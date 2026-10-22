<?php

namespace Tests\Feature\Analytics;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;

/**
 * Deals of the sales velocity tests: a deal in the stage of its status, owned by a user, created and closed at the
 * given instants.
 */
final class SalesVelocityFixture
{
    public static function deal(Organization $organization, User $creator, DealStatus $status, ?string $value, ?User $owner, string $createdAt, ?string $closedAt = null): Deal
    {
        return Deal::factory()
            ->inStage(match ($status) {
                DealStatus::Open => 'Lead',
                DealStatus::Won => 'Won',
                DealStatus::Lost => 'Lost',
            })
            ->create([
                'organization_id' => $organization->id,
                'created_by' => $creator->id,
                'owner_id' => $owner?->id,
                'status' => $status,
                'value' => $value,
                'won_at' => $status === DealStatus::Won ? $closedAt : null,
                'lost_at' => $status === DealStatus::Lost ? $closedAt : null,
                'created_at' => $createdAt,
            ]);
    }

    /**
     * The shared scenario: won 1000 (10 days, Test) and 2000 (30 days, Bob) this month, lost 500 (Test) this month and
     * 800 (Test) last month, three open deals (two of Test, one of Bob), and noise in another organization.
     */
    public static function build(Organization $organization, User $test, User $bob, Organization $other): void
    {
        self::deal($organization, $test, DealStatus::Won, '1000', $test, '2026-10-01 10:00:00', '2026-10-11 10:00:00');
        self::deal($organization, $test, DealStatus::Won, '2000', $bob, '2026-09-11 10:00:00', '2026-10-11 10:00:00');
        self::deal($organization, $test, DealStatus::Lost, '500', $test, '2026-09-25 10:00:00', '2026-10-05 10:00:00');
        self::deal($organization, $test, DealStatus::Lost, '800', $test, '2026-09-01 10:00:00', '2026-09-20 10:00:00');
        self::deal($organization, $test, DealStatus::Open, '100', $test, '2025-01-10 10:00:00');
        self::deal($organization, $test, DealStatus::Open, '100', $test, '2026-10-02 10:00:00');
        self::deal($organization, $test, DealStatus::Open, '100', $bob, '2026-10-03 10:00:00');

        self::deal($other, $test, DealStatus::Won, '9000', null, '2026-10-01 10:00:00', '2026-10-12 10:00:00');
        self::deal($other, $test, DealStatus::Open, '100', null, '2026-10-02 10:00:00');
        self::deal($other, $test, DealStatus::Open, '100', null, '2026-10-03 10:00:00');
    }
}
