<?php

use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReportPeriodPreset;
use App\Models\Contact;
use App\Models\User;
use App\Services\Reports\RevenueByClient;
use App\Support\Reports\ReportPeriod;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\RevenueByClientFixture;

beforeEach(function () {
    $this->travelTo(RevenueByClientFixture::now());
    $this->money = RevenueByClientFixture::build();

    $this->thisMonth = fn (?int $ownerId = null): array => RevenueByClient::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth), $ownerId);
    $this->lastMonth = fn (?int $ownerId = null): array => RevenueByClient::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::LastMonth), $ownerId);

    $this->freshOrg = function (): array {
        $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Fresh Founder']);
        $org = $user->personalOrganization();
        $org->update(['timezone' => 'Europe/Paris']);
        $this->actingAs($user);
        Filament::setTenant($org->refresh());

        return [$user, $org];
    };
});

test('this month, everyone', function () {
    $rows = ($this->thisMonth)();
    $companies = $this->money->companies;
    $contacts = $this->money->contacts;

    expect(array_keys($rows))->toBe([
        'company-'.$companies['Northwind']->id,
        'company-'.$companies['Umbrella']->id,
        'contact-'.$contacts['Ivan Ivanov']->id,
        'contact-'.$contacts['Zoe Zimmer']->id,
        'company-'.$companies['Initech']->id,
        'total',
    ])
        ->and(array_values($rows))->toBe([
            ['client' => 'Northwind', 'kind' => 'Company', 'is_total' => false, 'collected' => 6000.0, 'share' => '41%', 'outstanding' => 3000.0],
            ['client' => 'Umbrella (deleted)', 'kind' => 'Company', 'is_total' => false, 'collected' => 4000.0, 'share' => '28%', 'outstanding' => 0.0],
            ['client' => 'Ivan Ivanov', 'kind' => 'Contact', 'is_total' => false, 'collected' => 2500.0, 'share' => '17%', 'outstanding' => 2500.0],
            ['client' => 'Zoe Zimmer', 'kind' => 'Contact', 'is_total' => false, 'collected' => 2000.0, 'share' => '14%', 'outstanding' => 1000.0],
            ['client' => 'Initech', 'kind' => 'Company', 'is_total' => false, 'collected' => 0.0, 'share' => '0%', 'outstanding' => 1500.0],
            ['client' => 'Total', 'kind' => null, 'is_total' => true, 'collected' => 14500.0, 'share' => '100%', 'outstanding' => 8000.0],
        ])
        ->and($rows)->not->toHaveKey('others');
});

test('another organization is never counted', function () {
    $labels = array_column(($this->thisMonth)(), 'client');

    expect($labels)->not->toContain('Gus Globex', 'Hooli')
        ->and(($this->thisMonth)()['total']['collected'])->toBe(14500.0);
});

test('the deal company wins over the contact company', function () {
    $rows = ($this->thisMonth)();

    expect($rows['company-'.$this->money->companies['Initech']->id]['outstanding'])->toBe(1500.0)
        ->and($rows['company-'.$this->money->companies['Northwind']->id]['outstanding'])->toBe(3000.0)
        ->and($rows['company-'.$this->money->companies['Umbrella']->id]['client'])->toBe('Umbrella (deleted)');
});

test('a contact with two companies counts as the contact', function () {
    $row = ($this->thisMonth)()['contact-'.$this->money->contacts['Ivan Ivanov']->id];

    expect($row['client'])->toBe('Ivan Ivanov')
        ->and($row['kind'])->toBe('Contact');
});

test('a soft-deleted contact keeps its row', function () {
    $this->money->contacts['Zoe Zimmer']->delete();

    $row = ($this->thisMonth)()['contact-'.$this->money->contacts['Zoe Zimmer']->id];

    expect($row['client'])->toBe('Zoe Zimmer (deleted)')
        ->and($row['collected'])->toBe(2000.0);
});

test('a contact whose only company was deleted counts as the contact', function () {
    $this->money->companies['Northwind']->delete();

    $rows = ($this->thisMonth)();

    expect($rows)->toHaveKey('contact-'.$this->money->contacts['Nora Nash']->id)
        ->and($rows['contact-'.$this->money->contacts['Nora Nash']->id]['collected'])->toBe(6000.0);
});

test('the previous month', function () {
    $rows = array_values(($this->lastMonth)());

    expect(array_map(fn (array $row): array => [$row['client'], $row['collected'], $row['share'], $row['outstanding']], $rows))->toBe([
        ['Northwind', 1000.0, '100%', 3000.0],
        ['Ivan Ivanov', 0.0, '0%', 2500.0],
        ['Initech', 0.0, '0%', 1500.0],
        ['Zoe Zimmer', 0.0, '0%', 1000.0],
        ['Total', 1000.0, '100%', 8000.0],
    ]);
});

test('the owner filter follows the invoice creator', function () {
    $rows = array_values(($this->thisMonth)($this->money->bob->id));

    expect(array_map(fn (array $row): array => [$row['client'], $row['collected'], $row['share'], $row['outstanding']], $rows))->toBe([
        ['Umbrella (deleted)', 4000.0, '100%', 0.0],
        ['Initech', 0.0, '0%', 1500.0],
        ['Total', 4000.0, '100%', 1500.0],
    ]);
});

test('invoices without a creator only count for everyone', function () {
    Auth::logout();
    $invoice = $this->money->invoice('H', $this->money->contacts['Zoe Zimmer'], null, null, InvoiceStatus::Paid, '700', '700');
    $this->actingAs($this->money->alice);
    $this->money->ledger($invoice, '700', '2026-10-06');

    expect(($this->thisMonth)()['total']['collected'])->toBe(15200.0)
        ->and(($this->thisMonth)($this->money->alice->id)['total']['collected'])->toBe(10500.0)
        ->and(($this->thisMonth)($this->money->bob->id)['total']['collected'])->toBe(4000.0);
});

test('only the top ten clients are listed and the others are summed', function () {
    [$alice, $org] = ($this->freshOrg)();

    foreach (range(1, 12) as $n) {
        $contact = Contact::factory()->create(['organization_id' => $org->id, 'name' => sprintf('Client %02d', $n)]);
        $invoice = $this->money->invoice('F'.$n, $contact, null, $alice, InvoiceStatus::Paid, (string) (100 * $n), (string) (100 * $n), $org);
        $this->money->ledger($invoice, (string) (100 * $n), '2026-10-05');
    }

    $rows = array_values(($this->thisMonth)());

    expect($rows)->toHaveCount(12)
        ->and($rows[0]['client'])->toBe('Client 12')
        ->and($rows[0]['collected'])->toBe(1200.0)
        ->and($rows[0]['share'])->toBe('15%')
        ->and($rows[9]['client'])->toBe('Client 03')
        ->and($rows[10])->toBe(['client' => 'Other clients (2)', 'kind' => null, 'is_total' => false, 'collected' => 300.0, 'share' => '4%', 'outstanding' => 0.0])
        ->and($rows[11]['collected'])->toBe(7800.0)
        ->and($rows[11]['share'])->toBe('100%');
});

test('ties are sorted by outstanding then by name', function () {
    [$alice, $org] = ($this->freshOrg)();

    foreach (['Beta' => '50', 'Alpha' => '50', 'Gamma' => '90'] as $name => $balance) {
        $contact = Contact::factory()->create(['organization_id' => $org->id, 'name' => $name]);
        $this->money->invoice($name, $contact, null, $alice, InvoiceStatus::Sent, $balance, '0', $org);
    }

    expect(array_column(($this->thisMonth)(), 'client'))->toBe(['Gamma', 'Alpha', 'Beta', 'Total']);
});

test('a net negative month has no share', function () {
    [$alice, $org] = ($this->freshOrg)();
    $contact = Contact::factory()->create(['organization_id' => $org->id, 'name' => 'Nina Nobody']);
    $invoice = $this->money->invoice('N', $contact, null, $alice, InvoiceStatus::Sent, '1000', '0', $org);
    $this->money->ledger($invoice, '1000', '2026-09-15');
    $this->money->ledger($invoice, '1000', '2026-10-05', refund: true);

    $rows = array_values(($this->thisMonth)());

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['collected'])->toBe(-1000.0)
        ->and($rows[0]['share'])->toBe('—')
        ->and($rows[0]['outstanding'])->toBe(1000.0)
        ->and($rows[1]['collected'])->toBe(-1000.0)
        ->and($rows[1]['share'])->toBe('—');
});

test('an organization without invoices has no rows', function () {
    ($this->freshOrg)();

    expect(($this->thisMonth)())->toBe([]);
});

test('cents are summed exactly', function () {
    [$alice, $org] = ($this->freshOrg)();

    foreach (['0.10', '0.20'] as $n => $amount) {
        $contact = Contact::factory()->create(['organization_id' => $org->id, 'name' => 'Cent '.$n]);
        $invoice = $this->money->invoice('C'.$n, $contact, null, $alice, InvoiceStatus::Paid, $amount, $amount, $org);
        $this->money->ledger($invoice, $amount, '2026-10-05');
    }

    expect(($this->thisMonth)()['total']['collected'])->toBe(0.3);
});

test('the number of queries does not depend on the number of clients', function () {
    $count = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        ($this->thisMonth)();

        return $queries;
    };

    $before = $count();

    foreach (range(1, 20) as $n) {
        $contact = Contact::factory()->create(['organization_id' => $this->money->org->id, 'name' => 'Extra '.$n]);
        $invoice = $this->money->invoice('Z'.$n, $contact, $n % 2 === 0 ? $this->money->deal('Extra deal '.$n, $this->money->companies['Northwind']) : null, $this->money->alice, InvoiceStatus::Paid, '100', '100');
        $this->money->ledger($invoice, '100', '2026-10-05');
    }

    expect($count())->toBe($before);
});

test('every role gets the same rows', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->money->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($this->money->org);

    expect(($this->thisMonth)()['total']['collected'])->toBe(14500.0);
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);
