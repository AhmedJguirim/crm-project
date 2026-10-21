<?php

namespace Tests\Feature\Analytics;

use App\Enums\ActivityType;
use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

/**
 * The team leaderboard scenario shared by the service and widget tests: organization "Acme" (Europe/Paris, USD) with
 * Alice (owner), Bob (member), Vera (viewer, no records), Fred (former member) and Carol (another organization), and
 * their records around "now" = 2026-10-21 10:00 UTC.
 */
final class TeamActivityFixture
{
    public Organization $org;

    public Organization $globex;

    public User $alice;

    public User $bob;

    public User $vera;

    public User $fred;

    public User $carol;

    private Contact $contact;

    public static function build(): self
    {
        $fixture = new self;

        $fixture->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Anders']);
        $fixture->org = $fixture->alice->personalOrganization();
        $fixture->org->update(['timezone' => 'Europe/Paris']);

        $fixture->bob = $fixture->member('Bob Brown', OrganizationRole::Member);
        $fixture->vera = $fixture->member('Vera Viewer', OrganizationRole::Viewer);
        $fixture->fred = $fixture->member('Fred Former', OrganizationRole::Member);

        $fixture->carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Carol Clark']);
        $fixture->globex = $fixture->carol->personalOrganization();

        test()->actingAs($fixture->alice);
        Filament::setTenant($fixture->org->refresh());

        $fixture->contact = Contact::factory()->create(['organization_id' => $fixture->org->id]);

        $fixture->records();

        $fixture->org->members()->detach($fixture->fred);

        return $fixture;
    }

    public function member(string $name, OrganizationRole $role, ?Organization $organization = null): User
    {
        $user = User::factory()->onboardingCompleted()->create(['name' => $name]);
        ($organization ?? $this->org)->members()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function activity(?User $user, ActivityType $type, string $occurredAt, ?Organization $organization = null): Activity
    {
        $organization ??= $this->org;

        return Activity::factory()->type($type)->create([
            'organization_id' => $organization->id,
            'contact_id' => $organization->is($this->org) ? $this->contact->id : Contact::factory()->create(['organization_id' => $organization->id])->id,
            'user_id' => $user?->id,
            'occurred_at' => $occurredAt,
        ]);
    }

    public function task(?User $owner, TaskStatus $status, ?string $completedAt = null, ?string $dueAt = null, ?Organization $organization = null): Task
    {
        return Task::factory()->create([
            'organization_id' => ($organization ?? $this->org)->id,
            'owner_id' => $owner?->id,
            'status' => $status,
            'completed_at' => $completedAt,
            'due_at' => $dueAt,
            'created_by' => $this->alice->id,
        ]);
    }

    public function deal(DealStatus $status, ?string $value, ?User $owner, string $createdAt, ?string $closedAt = null, ?Organization $organization = null): Deal
    {
        $organization ??= $this->org;

        return Deal::factory()
            ->inStage(match ($status) {
                DealStatus::Open => 'Lead',
                DealStatus::Won => 'Won',
                DealStatus::Lost => 'Lost',
            })
            ->create([
                'organization_id' => $organization->id,
                'created_by' => $this->alice->id,
                'owner_id' => $owner?->id,
                'status' => $status,
                'value' => $value,
                'won_at' => $status === DealStatus::Won ? $closedAt : null,
                'lost_at' => $status === DealStatus::Lost ? $closedAt : null,
                'created_at' => $createdAt,
            ]);
    }

    private function records(): void
    {
        $this->activity($this->alice, ActivityType::Call, '2026-10-05 09:00:00');
        $this->activity($this->alice, ActivityType::Call, '2026-10-06 09:00:00');
        $this->activity($this->alice, ActivityType::Email, '2026-10-07 09:00:00');
        $this->activity($this->alice, ActivityType::Meeting, '2026-09-30 22:00:00');
        $this->activity($this->alice, ActivityType::Call, '2026-09-30 21:59:59');
        $this->activity($this->bob, ActivityType::WhatsApp, '2026-10-08 09:00:00');
        $this->activity(null, ActivityType::Call, '2026-10-08 09:00:00');

        $this->task($this->alice, TaskStatus::Done, completedAt: '2026-10-10 09:00:00');
        $this->task($this->alice, TaskStatus::Done, completedAt: '2026-09-15 09:00:00');
        $this->task($this->alice, TaskStatus::Pending, dueAt: '2026-10-20 10:00:00');
        $this->task($this->alice, TaskStatus::Pending, dueAt: '2026-10-22 10:00:00');
        $this->task($this->alice, TaskStatus::Pending, dueAt: '2026-10-19 10:00:00')->delete();
        $this->task($this->bob, TaskStatus::Done, completedAt: '2026-10-20 09:00:00');
        $this->task($this->bob, TaskStatus::Pending, dueAt: '2026-10-23 10:00:00');
        $this->task(null, TaskStatus::Pending, dueAt: '2026-10-19 10:00:00');

        $this->deal(DealStatus::Won, '1000', $this->alice, '2026-10-02 10:00:00', '2026-10-10 10:00:00');
        $this->deal(DealStatus::Won, '500', $this->alice, '2026-09-01 10:00:00', '2026-10-03 10:00:00');
        $this->deal(DealStatus::Lost, '700', $this->alice, '2026-10-04 10:00:00', '2026-10-12 10:00:00');
        $this->deal(DealStatus::Open, '800', $this->alice, '2026-10-15 10:00:00');
        $this->deal(DealStatus::Won, '2000', $this->bob, '2026-10-01 09:00:00', '2026-10-18 10:00:00');
        $this->deal(DealStatus::Won, '300', $this->fred, '2026-09-10 10:00:00', '2026-10-05 10:00:00');
        $this->deal(DealStatus::Won, '5000', null, '2026-10-02 10:00:00', '2026-10-06 10:00:00');

        $this->deal(DealStatus::Won, '9000', $this->carol, '2026-10-02 10:00:00', '2026-10-06 10:00:00', $this->globex);
        $this->activity($this->carol, ActivityType::Call, '2026-10-06 10:00:00', $this->globex);
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC');
    }
}
