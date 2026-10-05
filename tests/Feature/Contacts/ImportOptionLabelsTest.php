<?php

use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use App\Services\ContactImportService;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->stack = CustomField::factory()->for($this->org)->create(['name' => 'Stack', 'type' => 'multiselect', 'unique' => false, 'order' => 1, 'options' => [['label' => 'PHP / Laravel'], ['label' => 'AWS']]]);
    $this->seniority = CustomField::factory()->for($this->org)->create(['name' => 'Seniority', 'type' => 'select', 'unique' => false, 'order' => 2, 'options' => [['label' => 'Junior'], ['label' => 'Senior']]]);

    $this->valueOf = fn (CustomField $field, string $label): string => collect($field->options)->firstWhere('label', $label)['value'];
    $this->import = fn (array $row): array => (new ContactImportService($this->org->id))->processRow(
        ['name' => 'Ann', 'email' => 'ann@example.test', ...$row],
        ['Stack' => $this->stack, 'Seniority' => $this->seniority],
    );
});

it('accepts labels ignoring case and spaces, and stores the values', function () {
    $result = ($this->import)(['Stack' => 'php / laravel; AWS', 'Seniority' => ' senior ']);

    $contact = Contact::where('email', 'ann@example.test')->sole();

    expect($result['success'])->toBeTrue()
        ->and($contact->customFieldValue($this->stack->key))->toBe([($this->valueOf)($this->stack, 'PHP / Laravel'), ($this->valueOf)($this->stack, 'AWS')])
        ->and($contact->customFieldValue($this->seniority->key))->toBe(($this->valueOf)($this->seniority, 'Senior'));
});

it('still accepts stored values, alone or mixed with labels, without repeating an option', function () {
    $aws = ($this->valueOf)($this->stack, 'AWS');

    $result = ($this->import)(['Stack' => "{$aws};AWS;PHP / Laravel"]);

    expect($result['success'])->toBeTrue()
        ->and(Contact::sole()->customFieldValue($this->stack->key))->toBe([$aws, ($this->valueOf)($this->stack, 'PHP / Laravel')]);
});

it('refuses unknown labels', function () {
    expect(($this->import)(['Stack' => 'Kotlin']))->toBe(['success' => false, 'error' => "Invalid value for field 'Stack': Kotlin"])
        ->and(($this->import)(['Stack' => 'AWS;Kotlin'])['success'])->toBeFalse()
        ->and(($this->import)(['Seniority' => 'Lead'])['error'])->toBe("Invalid value for field 'Seniority': Lead")
        ->and(Contact::count())->toBe(0);
});

it('shows labels in the template examples', function () {
    $service = new ContactImportService($this->org->id);

    expect($service->exampleValueFor($this->stack))->toBe('PHP / Laravel;AWS')
        ->and($service->exampleValueFor($this->seniority))->toBe('Junior');
});
