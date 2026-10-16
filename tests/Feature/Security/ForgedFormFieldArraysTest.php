<?php

use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\CompanyTypes\Pages\ManageCompanyTypes;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\PipelineStages\Pages\ManagePipelineStages;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->agency = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Agency']);
    CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Startup']);

    $this->forgedStageEdit = function (string $stage, array $data) {
        $record = pipelineStageNamed($this->org->id, $stage)->refresh();

        return Livewire::test(ManagePipelineStages::class)
            ->mountAction(TestAction::make(EditAction::class)->table($record))
            ->set('mountedActions.0.data', $data)
            ->call('callMountedAction');
    };
});

dataset('forged stage arrays', [
    'name' => ['Discovery', ['name' => ['x'], 'color' => 'info', 'probability' => 25], 'name', 'info', 25],
    'color' => ['Lead', ['name' => 'Lead', 'color' => ['danger'], 'probability' => 10], 'color', 'gray', 10],
    'probability' => ['Discovery', ['name' => 'Discovery', 'color' => 'info', 'probability' => ['30']], 'probability', 'info', 25],
]);

it('refuses a forged array in a pipeline stage field with one single-value error', function (string $stage, array $data, string $field, string $color, int $probability) {
    $page = ($this->forgedStageEdit)($stage, $data);

    $page->assertHasActionErrors([$field]);
    expect($page->errors()->get("mountedActions.0.data.{$field}"))->toBe(['The '.($field === 'probability' ? 'win probability' : $field).' field must be a single value.']);

    $record = pipelineStageNamed($this->org->id, $stage)->refresh();
    expect($record->name)->toBe($stage)
        ->and($record->color)->toBe($color)
        ->and($record->probability)->toBe($probability);
})->with('forged stage arrays');

it('refuses a forged array as the name of a new company type', function () {
    $page = Livewire::test(ManageCompanyTypes::class)
        ->mountAction(CreateAction::class)
        ->set('mountedActions.0.data', ['name' => ['x']])
        ->call('callMountedAction');

    $page->assertHasActionErrors(['name']);
    expect($page->errors()->get('mountedActions.0.data.name'))->toBe(['The name field must be a single value.'])
        ->and(CompanyType::count())->toBe(2);
});

it('refuses a forged array as the name of an edited company type', function () {
    $page = Livewire::test(ManageCompanyTypes::class)
        ->mountAction(TestAction::make(EditAction::class)->table($this->agency))
        ->set('mountedActions.0.data', ['name' => ['x']])
        ->call('callMountedAction');

    $page->assertHasActionErrors(['name']);
    expect($page->errors()->get('mountedActions.0.data.name'))->toBe(['The name field must be a single value.'])
        ->and($this->agency->refresh()->name)->toBe('Agency');
});

it('guards a field that has no closure rule of its own', function () {
    $page = Livewire::test(CreateContact::class)
        ->set('data.name', ['x'])
        ->set('data.email', 'forged@example.com')
        ->call('create');

    $page->assertHasFormErrors(['name']);
    expect($page->errors()->get('data.name'))->toBe(['The name field must be a single value.'])
        ->and(Contact::where('email', 'forged@example.com')->exists())->toBeFalse();
});

it('guards a textarea', function () {
    $page = Livewire::test(CreateCompany::class)
        ->set('data.name', 'Forged Textarea Co')
        ->set('data.notes', ['x'])
        ->call('create');

    $page->assertHasFormErrors(['notes']);
    expect($page->errors()->get('data.notes'))->toBe(['The notes field must be a single value.'])
        ->and(Company::where('name', 'Forged Textarea Co')->exists())->toBeFalse();
});

it('guards a color picker', function () {
    $page = Livewire::test(CreateTag::class)
        ->set('data.name', 'Forged Color')
        ->set('data.color', ['#ff0000'])
        ->call('create');

    $page->assertHasFormErrors(['color']);
    expect($page->errors()->get('data.color'))->toBe(['The color field must be a single value.'])
        ->and(Tag::where('name', 'Forged Color')->exists())->toBeFalse();
});

it('still takes a list in a multiple select', function () {
    $tag = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);

    Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Jane Doe', 'email' => 'jane.doe@example.test', 'tags' => [$tag->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contact::where('email', 'jane.doe@example.test')->sole()->tags->pluck('name')->all())->toBe(['VIP']);
});

it('does not stop a scalar value at its first failed rule', function () {
    $page = Livewire::test(CreateContact::class)
        ->set('data.name', 'Jane Doe')
        ->set('data.email', 'not-an-email-'.str_repeat('x', 300))
        ->call('create');

    expect($page->errors()->get('data.email'))->toHaveCount(2);
});

it('validates scalar input exactly as before', function () {
    $page = ($this->forgedStageEdit)('Discovery', ['name' => 'Qualified', 'color' => 'warning', 'probability' => 30]);

    $page->assertHasNoActionErrors();

    $record = pipelineStageNamed($this->org->id, 'Qualified');
    expect($record->color)->toBe('warning')
        ->and($record->probability)->toBe(30);
});
