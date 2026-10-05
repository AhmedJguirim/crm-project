<?php

use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->company = fn (string $name, array $attributes = []): Company => Company::factory()->create(['organization_id' => $this->org->id, 'name' => $name, ...$attributes]);
});

it('sets both values from the form', function () {
    Livewire::test(CreateCompany::class)
        ->fillForm(['name' => 'Acme', 'employees' => '250', 'annual_revenue' => '1250000.50'])
        ->call('create')
        ->assertHasNoFormErrors();

    $acme = Company::query()->where('name', 'Acme')->sole();

    expect($acme->employees)->toBe(250)
        ->and($acme->annual_revenue)->toBe('1250000.50');
});

it('clears both values to null, not zero', function () {
    $acme = ($this->company)('Acme', ['employees' => 250, 'annual_revenue' => 1000]);

    Livewire::test(EditCompany::class, ['record' => $acme->id])
        ->fillForm(['employees' => '', 'annual_revenue' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($acme->fresh()->only(['employees', 'annual_revenue']))->toBe(['employees' => null, 'annual_revenue' => null]);
});

it('accepts zero', function () {
    Livewire::test(CreateCompany::class)
        ->fillForm(['name' => 'Acme', 'employees' => '0', 'annual_revenue' => '0'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Company::query()->where('name', 'Acme')->sole()->only(['employees', 'annual_revenue']))->toBe(['employees' => 0, 'annual_revenue' => '0.00']);
});

it('refuses invalid values', function (string $field, string $value) {
    Livewire::test(CreateCompany::class)
        ->fillForm(['name' => 'Acme', $field => $value])
        ->call('create')
        ->assertHasFormErrors([$field]);

    expect(Company::count())->toBe(0);
})->with([
    ['employees', '-1'],
    ['employees', '2.5'],
    ['annual_revenue', '-10'],
    ['annual_revenue', 'abc'],
    ['annual_revenue', '10000000000000'],
]);

it('keeps a revenue without float rounding', function () {
    $acme = ($this->company)('Acme', ['annual_revenue' => '9999999999999.99']);

    expect($acme->fresh()->annual_revenue)->toBe('9999999999999.99');
});

it('shows the labels in the form', function () {
    $fields = Livewire::test(CreateCompany::class)->instance()->getSchema('form')->getFlatFields();

    expect($fields['employees']->getLabel())->toBe('Employees')
        ->and($fields['annual_revenue']->getLabel())->toBe('Annual revenue');
});

describe('the table', function () {
    it('sorts by employees', function () {
        $a = ($this->company)('A', ['employees' => 10]);
        $b = ($this->company)('B', ['employees' => 500]);
        $c = ($this->company)('C');

        Livewire::test(ListCompanies::class)
            ->sortTable('employees', 'desc')
            ->assertCanSeeTableRecords([$b, $a], inOrder: true);
    });

    it('sorts by annual revenue', function () {
        $small = ($this->company)('Small', ['annual_revenue' => 1000]);
        $big = ($this->company)('Big', ['annual_revenue' => 180000000]);

        Livewire::test(ListCompanies::class)
            ->sortTable('annual_revenue', 'desc')
            ->assertCanSeeTableRecords([$big, $small], inOrder: true);
    });

    it('formats the revenue with separators and no decimals, and hides both columns by default', function () {
        $acme = ($this->company)('Acme', ['employees' => 1200, 'annual_revenue' => 180000000]);
        $cents = ($this->company)('Cents', ['annual_revenue' => '1250000.40']);

        $table = Livewire::test(ListCompanies::class)
            ->assertTableColumnFormattedStateSet('annual_revenue', '180,000,000', $acme)
            ->assertTableColumnFormattedStateSet('annual_revenue', '1,250,000', $cents)
            ->assertTableColumnFormattedStateSet('employees', '1,200', $acme)
            ->instance()->getTable();

        expect($table->getColumn('employees')->isToggledHiddenByDefault())->toBeTrue()
            ->and($table->getColumn('annual_revenue')->isToggledHiddenByDefault())->toBeTrue()
            ->and($table->getColumn('employees')->isSortable())->toBeTrue()
            ->and($table->getColumn('annual_revenue')->getLabel())->toBe('Annual revenue');
    });

    it('shows a dash for a missing value', function () {
        $acme = ($this->company)('Acme');

        $column = Livewire::test(ListCompanies::class)->instance()->getTable()->getColumn('employees')->record($acme);

        expect($column->getPlaceholder())->toBe('—');
    });
});
