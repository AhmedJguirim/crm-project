<?php

use App\Enums\DealStatus;
use App\Enums\LeadSource;
use App\Enums\ReportPeriodPreset;
use App\Models\User;
use App\Services\Reports\LeadSources;
use App\Support\Reports\ReportPeriod;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\LeadSourcesFixture;

beforeEach(function () {
    $this->travelTo(LeadSourcesFixture::now());
    $this->sources = LeadSourcesFixture::build();

    $this->thisMonth = fn (?int $ownerId = null): array => LeadSources::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth), $ownerId);
    $this->lastMonth = fn (?int $ownerId = null): array => LeadSources::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::LastMonth), $ownerId);

    $this->freshOrg = function (): array {
        $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Fresh Founder']);
        $org = $user->personalOrganization();
        $org->update(['timezone' => 'Europe/Paris']);
        $this->actingAs($user);
        Filament::setTenant($org->refresh());

        return [$user, $org];
    };

    $this->figures = fn (array $rows): array => array_map(
        fn (array $row): array => [$row['source'], $row['new_contacts'], $row['won_count'], $row['won_value'], $row['share']],
        array_values($rows),
    );
});

test('this month, everyone', function () {
    $rows = ($this->thisMonth)();

    expect(array_keys($rows))->toBe(['referral', 'upwork', 'website', 'linkedin', 'conference', 'unknown', 'total'])
        ->and(($this->figures)($rows))->toBe([
            ['Referral', 2, 1, 5000.0, '32%'],
            ['Upwork', 0, 1, 4000.0, '26%'],
            ['Website', 0, 1, 3000.0, '19%'],
            ['LinkedIn', 1, 1, 2000.0, '13%'],
            ['Conference', 1, 0, 0.0, '0%'],
            ['Unknown source', 1, 2, 1500.0, '10%'],
            ['Total', 5, 6, 15500.0, '100%'],
        ])
        ->and(array_column($rows, 'is_total'))->toBe([false, false, false, false, false, false, true]);
});

test('the owner filter applies to the contact owner and to the deal owner', function () {
    expect(($this->figures)(($this->thisMonth)($this->sources->bob->id)))->toBe([
        ['Website', 0, 1, 3000.0, '100%'],
        ['Referral', 1, 0, 0.0, '0%'],
        ['Total', 1, 1, 3000.0, '100%'],
    ]);
});

test('last month, everyone', function () {
    expect(($this->figures)(($this->lastMonth)()))->toBe([
        ['Referral', 0, 1, 7000.0, '100%'],
        ['Conference', 1, 0, 0.0, '0%'],
        ['Website', 1, 0, 0.0, '0%'],
        ['Total', 2, 1, 7000.0, '100%'],
    ]);
});

test('a soft-deleted contact is no new contact but still gives its source to its won deals', function () {
    $rows = ($this->thisMonth)();

    expect($rows['upwork']['new_contacts'])->toBe(0)
        ->and($rows['upwork']['won_count'])->toBe(1);
});

test('open, lost and other organization deals never count', function () {
    $rows = ($this->thisMonth)();

    expect($rows['total']['won_count'])->toBe(6)
        ->and($rows['total']['won_value'])->toBe(15500.0)
        ->and($rows['referral']['won_value'])->toBe(5000.0)
        ->and($rows['linkedin']['won_value'])->toBe(2000.0);
});

test('a won deal moved to another status stops counting', function () {
    $this->sources->deals['D1']->update(['status' => DealStatus::Open, 'won_at' => null]);

    expect(($this->thisMonth)()['referral']['won_count'])->toBe(0);
});

test('no won value gives dash shares', function () {
    [, $org] = ($this->freshOrg)();
    $this->sources->contact('Lone Linked', LeadSource::LinkedIn, null, '2026-10-02 10:00:00', $org);

    expect(($this->figures)(($this->thisMonth)()))->toBe([
        ['LinkedIn', 1, 0, 0.0, '—'],
        ['Total', 1, 0, 0.0, '—'],
    ]);
});

test('a lead source the enum does not know counts as unknown', function () {
    DB::table('contacts')->where('id', $this->sources->contacts['Cat Moe']->id)->update(['lead_source' => 'fax']);

    $rows = ($this->thisMonth)();

    expect($rows)->not->toHaveKey('linkedin')
        ->and(($this->figures)([$rows['unknown']]))->toBe([['Unknown source', 2, 3, 3500.0, '23%']]);
});

test('sources tied on value are ordered by deals won, new contacts, then label', function () {
    [$user, $org] = ($this->freshOrg)();
    $this->sources->contact('A1', LeadSource::Website, $user, '2026-10-02 10:00:00', $org);
    $this->sources->contact('A2', LeadSource::Website, $user, '2026-10-02 10:00:00', $org);
    $this->sources->contact('B1', LeadSource::Inbound, $user, '2026-10-02 10:00:00', $org);
    $this->sources->contact('C1', LeadSource::Conference, $user, '2026-10-02 10:00:00', $org);
    $this->sources->contact('D1', LeadSource::Other, $user, '2026-10-02 10:00:00', $org);
    $this->sources->deal('T1', null, DealStatus::Won, '100', $user, '2026-10-03 12:00:00', $org);

    expect(array_keys(($this->thisMonth)()))->toBe(['website', 'conference', 'inbound', 'other', 'unknown', 'total']);
});

test('nothing to show', function () {
    ($this->freshOrg)();

    expect(($this->thisMonth)())->toBe([]);
});

test('the number of queries is constant', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();

    ($this->thisMonth)();

    expect(DB::getQueryLog())->toHaveCount(2);
});

test('another organization never leaks in', function () {
    $this->actingAs($this->sources->carol);
    Filament::setTenant($this->sources->globex->refresh());

    expect(($this->figures)(($this->thisMonth)()))->toBe([
        ['Referral', 1, 1, 9999.0, '100%'],
        ['Total', 1, 1, 9999.0, '100%'],
    ]);
});

test('a forged owner id from another organization matches nothing of this one', function () {
    expect(($this->thisMonth)($this->sources->carol->id))->toBe([]);
});

test('a lost or reopened deal that still has a won date in the period does not count', function () {
    $this->sources->deals['D7']->update(['won_at' => '2026-10-11 12:00:00']);
    $this->sources->deals['D8']->update(['won_at' => '2026-10-07 12:00:00']);

    $rows = ($this->thisMonth)();

    expect($rows['total']['won_count'])->toBe(6)
        ->and($rows['total']['won_value'])->toBe(15500.0);
});
