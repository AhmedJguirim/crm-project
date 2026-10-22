<?php

namespace Tests\Feature\Analytics;

use App\Enums\DealStatus;
use App\Enums\LeadSource;
use App\Enums\OrganizationRole;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

/**
 * The lead sources scenario shared by the service and widget tests: organization "Acme" (Europe/Paris, USD) with Alice
 * (owner) and Bob (member), eight contacts with their lead sources and creation dates (Fay Sun soft-deleted), deals D1
 * to D9 (see `records()`), and a Globex contact with a won deal, with "now" = 2026-10-21 10:00 UTC.
 */
final class LeadSourcesFixture
{
    public Organization $org;

    public Organization $globex;

    public User $alice;

    public User $bob;

    public User $carol;

    /** @var array<string, Contact> */
    public array $contacts = [];

    /** @var array<string, Deal> */
    public array $deals = [];

    public static function build(): self
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

        $fixture->records();

        test()->travelTo(self::now());

        return $fixture;
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC');
    }

    public function contact(string $name, ?LeadSource $source, ?User $owner, string $createdAt, ?Organization $organization = null): Contact
    {
        return $this->contacts[$name] = Contact::factory()->create([
            'organization_id' => ($organization ?? $this->org)->id,
            'name' => $name,
            'lead_source' => $source,
            'owner_id' => $owner?->id,
            'created_at' => $createdAt,
        ]);
    }

    public function deal(string $key, ?Contact $contact, DealStatus $status, ?string $value, ?User $owner, ?string $closedAt, ?Organization $organization = null): Deal
    {
        $organization ??= $this->org;

        return $this->deals[$key] = Deal::factory()
            ->inStage(match ($status) {
                DealStatus::Open => 'Lead',
                DealStatus::Won => 'Won',
                DealStatus::Lost => 'Lost',
            })
            ->create([
                'organization_id' => $organization->id,
                'contact_id' => $contact?->id,
                'created_by' => $this->alice->id,
                'owner_id' => $owner?->id,
                'status' => $status,
                'value' => $value,
                'won_at' => $status === DealStatus::Won ? $closedAt : null,
                'lost_at' => $status === DealStatus::Lost ? $closedAt : null,
            ]);
    }

    private function records(): void
    {
        $ann = $this->contact('Ann Lee', LeadSource::Referral, $this->alice, '2026-10-05 10:00:00');
        $this->contact('Ben Ode', LeadSource::Referral, $this->bob, '2026-10-20 10:00:00');
        $cat = $this->contact('Cat Moe', LeadSource::LinkedIn, $this->alice, '2026-10-10 10:00:00');
        $dan = $this->contact('Dan Poe', null, $this->alice, '2026-10-12 10:00:00');
        $eve = $this->contact('Eve Roe', LeadSource::Website, $this->alice, '2026-09-15 10:00:00');
        $fay = $this->contact('Fay Sun', LeadSource::Upwork, $this->alice, '2026-10-08 10:00:00');
        $this->contact('Gil Tan', LeadSource::Conference, $this->alice, '2026-09-30 22:30:00');
        $this->contact('Hal Vo', LeadSource::Conference, $this->alice, '2026-09-30 21:30:00');

        $this->deal('D1', $ann, DealStatus::Won, '5000', $this->alice, '2026-10-06 12:00:00');
        $this->deal('D2', $eve, DealStatus::Won, '3000', $this->bob, '2026-10-15 12:00:00');
        $this->deal('D3', $cat, DealStatus::Won, '2000', $this->alice, '2026-10-11 12:00:00');
        $this->deal('D4', null, DealStatus::Won, '1500', $this->alice, '2026-10-12 12:00:00');
        $this->deal('D5', $fay, DealStatus::Won, '4000', $this->alice, '2026-10-09 12:00:00');
        $this->deal('D6', $ann, DealStatus::Won, '7000', $this->alice, '2026-09-20 12:00:00');
        $this->deal('D7', $cat, DealStatus::Open, '50000', $this->alice, null);
        $this->deal('D8', $ann, DealStatus::Lost, '8000', $this->alice, '2026-10-07 12:00:00');
        $this->deal('D9', $dan, DealStatus::Won, null, $this->alice, '2026-10-13 12:00:00');

        $fay->delete();

        $gus = $this->contact('Gus Globex', LeadSource::Referral, $this->carol, '2026-10-10 10:00:00', $this->globex);
        $this->deal('X', $gus, DealStatus::Won, '9999', $this->carol, '2026-10-10 12:00:00', $this->globex);
    }
}
