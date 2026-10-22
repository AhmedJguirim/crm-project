<?php

namespace Tests\Feature\Analytics;

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use App\Services\Deals\DefaultPipeline;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

/**
 * The stage conversion scenario shared by the service and widget tests: organization "Acme" (Europe/Paris) with Alice
 * (owner) and Bob (member), seven deals moved through the default pipeline at known times (A to G, see `deals()`), and
 * one Globex deal, with "now" = 2026-10-21 10:00 UTC.
 */
final class StageConversionFixture
{
    public Organization $org;

    public Organization $globex;

    public User $alice;

    public User $bob;

    public User $carol;

    /** @var array<string, Deal> */
    public array $deals = [];

    public static function build(bool $withDeals = true): self
    {
        $fixture = new self;

        $fixture->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Anders']);
        $fixture->org = $fixture->alice->personalOrganization();
        $fixture->org->update(['timezone' => 'Europe/Paris']);

        $fixture->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Brown']);
        $fixture->org->members()->attach($fixture->bob, ['role' => OrganizationRole::Member->value]);

        $fixture->carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Carol Clark']);
        $fixture->globex = $fixture->carol->personalOrganization();

        test()->actingAs($fixture->alice);
        Filament::setTenant($fixture->org->refresh());

        if ($withDeals) {
            $fixture->deals();
        }

        test()->travelTo(self::now());

        return $fixture;
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC');
    }

    /**
     * Creates a deal in its first stage at `$createdAt`, then moves it to each `[time, stage]` step in order. The stage
     * "Lost" moves it to the Lost stage.
     *
     * @param  array<int, array{0: string, 1: string}>  $path
     */
    public function deal(string $title, string $createdAt, string $firstStage, ?User $owner, array $path = [], ?Organization $organization = null): Deal
    {
        $organization ??= $this->org;

        Filament::setTenant($organization);
        test()->travelTo(CarbonImmutable::parse($createdAt, 'UTC'));

        $deal = Deal::factory()->inStage($firstStage)->create([
            'organization_id' => $organization->id,
            'created_by' => $this->alice->id,
            'owner_id' => $owner?->id,
            'title' => $title,
            'status' => match ($firstStage) {
                'Won' => DealStatus::Won,
                'Lost' => DealStatus::Lost,
                default => DealStatus::Open,
            },
            'created_at' => $createdAt,
        ]);

        foreach ($path as [$at, $stage]) {
            test()->travelTo(CarbonImmutable::parse($at, 'UTC'));

            DealStageMover::move($deal, DefaultPipeline::stagesOf($organization->id)->firstWhere('name', $stage));
        }

        Filament::setTenant($this->org);

        return $this->deals[$title] = $deal;
    }

    private function deals(): void
    {
        $this->deal('A', '2026-10-02 09:00:00', 'Lead', $this->alice, [['2026-10-03 09:00:00', 'Discovery'], ['2026-10-04 09:00:00', 'Proposal Sent'], ['2026-10-05 09:00:00', 'Negotiating'], ['2026-10-06 09:00:00', 'Won']]);
        $this->deal('B', '2026-10-03 09:00:00', 'Lead', $this->bob, [['2026-10-04 09:00:00', 'Proposal Sent'], ['2026-10-05 09:00:00', 'Lost']]);
        $this->deal('C', '2026-10-04 09:00:00', 'Lead', $this->alice, [['2026-10-05 09:00:00', 'Lost']]);
        $this->deal('D', '2026-10-05 09:00:00', 'Discovery', $this->bob);
        $this->deal('E', '2026-10-06 09:00:00', 'Lost', null);
        $this->deal('F', '2026-10-07 09:00:00', 'Lead', $this->alice, [['2026-10-08 09:00:00', 'Won'], ['2026-10-09 09:00:00', 'Negotiating']]);
        $this->deal('G', '2026-09-30 21:59:59', 'Lead', $this->alice, [['2026-10-10 09:00:00', 'Won']]);
        $this->deal('Globex deal', '2026-10-08 09:00:00', 'Lead', $this->carol, [['2026-10-09 09:00:00', 'Won']], $this->globex);
    }
}
