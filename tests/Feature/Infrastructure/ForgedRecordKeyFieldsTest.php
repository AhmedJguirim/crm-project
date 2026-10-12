<?php

use App\Enums\ActivityType;
use App\Enums\DealStage;
use App\Enums\TaskType;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Support\StateCasts\RecordKeyIsReadable;
use App\Livewire\Filament\QuickTaskFloatingButton;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->alice->personalOrganization();
    $this->actingAs($this->alice);
    Filament::setTenant($this->org);

    $this->jane = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);
});

dataset('forged record key forms', [
    'deal contact' => ['deal', 'contact_id', 'abc', 'contact_id'],
    'deal company' => ['deal', 'company_id', 'abc', 'company_id'],
    'task contact' => ['task', 'contact_id', 'abc', 'contact_id'],
    'task deal' => ['task', 'deal_id', 'abc', 'deal_id'],
    'task contact, overflowing id' => ['task', 'contact_id', '9223372036854775808', 'contact_id'],
    'invoice contact' => ['invoice', 'contact_id', 'abc', 'contact_id'],
    'invoice deal' => ['invoice', 'deal_id', 'abc', 'deal_id'],
    'contact tags' => ['contact', 'tags', ['abc'], 'tags.0'],
    'contact companies' => ['contact', 'companies', ['abc'], 'companies.0'],
    'company type' => ['company', 'company_type_id', 'abc', 'company_type_id'],
]);

test('a forged non-integer id renders, then is refused on save', function (string $page, string $field, mixed $value, string $errorKey) {
    $component = match ($page) {
        'deal' => Livewire::test(CreateDeal::class)->fillForm(['title' => 'Forged', 'stage' => DealStage::Lead]),
        'task' => Livewire::test(CreateTask::class)->fillForm(['title' => 'Forged', 'contact_id' => $field === 'deal_id' ? $this->jane->id : null]),
        'invoice' => Livewire::test(CreateInvoice::class)->fillForm(['amount' => 10, 'contact_id' => $field === 'deal_id' ? $this->jane->id : null]),
        'contact' => Livewire::test(CreateContact::class)->fillForm(['name' => 'Forged', 'email' => 'forged@example.com']),
        'company' => Livewire::test(CreateCompany::class)->fillForm(['name' => 'Forged Ltd']),
    };

    $component->set('data.'.$field, $value)
        ->assertOk()
        ->call('create');

    expect($component->errors()->has('data.'.$errorKey))->toBeTrue()
        ->and($component->errors()->get('data.'.$errorKey)[0])->toContain('must be an integer');

    expect(match ($page) {
        'deal' => Deal::query()->where('title', 'Forged')->exists(),
        'task' => Task::query()->where('title', 'Forged')->exists(),
        'invoice' => Invoice::query()->where('amount', 10)->exists(),
        'contact' => Contact::query()->where('email', 'forged@example.com')->exists(),
        'company' => Company::query()->where('name', 'Forged Ltd')->exists(),
    })->toBeFalse();
})->with('forged record key forms');

test('a forged owner id on the deal form is refused by the options rule', function () {
    $component = Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Forged', 'stage' => DealStage::Lead])
        ->set('data.owner_id', 'abc')
        ->assertOk()
        ->call('create');

    expect($component->errors()->has('data.owner_id'))->toBeTrue()
        ->and(Deal::query()->where('title', 'Forged')->exists())->toBeFalse();
});

test('an edit page refuses a forged id and keeps the stored value', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->alice->id,
        'contact_id' => $this->jane->id,
        'title' => 'Retainer',
    ]);

    $component = Livewire::test(EditDeal::class, ['record' => $deal->getRouteKey()])
        ->set('data.contact_id', 'abc')
        ->assertOk()
        ->call('save');

    expect($component->errors()->has('data.contact_id'))->toBeTrue()
        ->and($deal->refresh()->contact_id)->toBe($this->jane->id);
});

test('the contact hook of the deal form ignores a forged id', function () {
    Livewire::test(CreateDeal::class)
        ->set('data.contact_id', 'abc')
        ->assertOk()
        ->assertSet('data.company_id', null);
});

test('the invoice deal hook and the task deal options ignore a forged contact id', function () {
    Livewire::test(CreateInvoice::class)
        ->set('data.contact_id', 'abc')
        ->set('data.deal_id', 'abc')
        ->assertOk();

    Livewire::test(CreateTask::class)
        ->set('data.contact_id', 'abc')
        ->assertOk();
});

test('forged ids in action modals are refused', function (string $action, array $data, string $errorKey) {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->alice->id,
        'contact_id' => $this->jane->id,
    ]);
    $tasksBefore = Task::query()->count();

    match ($action) {
        'floating' => Livewire::test(QuickTaskFloatingButton::class)
            ->mountAction('quickTask')
            ->set('mountedActions.0.data.title', $data['title'])
            ->set('mountedActions.0.data.contact_id', $data['contact_id'])
            ->callMountedAction()
            ->assertHasActionErrors([$errorKey => 'integer']),
        'contact' => Livewire::test(ViewContact::class, ['record' => $this->jane->id])
            ->mountAction('quickTask')
            ->set('mountedActions.0.data.title', $data['title'])
            ->set('mountedActions.0.data.contact_id', $data['contact_id'])
            ->callMountedAction()
            ->assertHasActionErrors([$errorKey => 'integer']),
        'log' => Livewire::test(ViewDeal::class, ['record' => $deal->id])
            ->mountAction('logActivity')
            ->set('mountedActions.0.data.type', ActivityType::Call->value)
            ->set('mountedActions.0.data.deal_id', $data['deal_id'])
            ->callMountedAction()
            ->assertHasActionErrors([$errorKey => 'integer']),
    };

    expect(Task::query()->count())->toBe($tasksBefore)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'floating quick task' => ['floating', ['title' => 'Forged', 'contact_id' => 'abc'], 'contact_id'],
    'locked contact quick task' => ['contact', ['title' => 'Forged', 'contact_id' => 'abc'], 'contact_id'],
    'log activity' => ['log', ['deal_id' => 'abc'], 'deal_id'],
]);

test('valid ids still work', function () {
    $tag = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);

    Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Kept', 'email' => 'kept@example.com', 'tags' => [(string) $tag->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contact::query()->where('email', 'kept@example.com')->first()->tags->pluck('id')->all())->toBe([$tag->id]);

    Livewire::test(CreateTask::class)
        ->fillForm(['title' => 'Valid', 'contact_id' => $this->jane->id, 'type' => TaskType::FollowUp])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Task::query()->where('title', 'Valid')->first()->contact_id)->toBe($this->jane->id);
});

test('a negative or zero id is refused by the exists rule without a crash', function (string $value) {
    $component = Livewire::test(CreateTask::class)
        ->fillForm(['title' => 'Negative'])
        ->set('data.contact_id', $value)
        ->assertOk()
        ->call('create');

    expect($component->errors()->has('data.contact_id'))->toBeTrue()
        ->and(Task::query()->where('title', 'Negative')->exists())->toBeFalse();
})->with(['-1', '0']);

test('a real id of another organization is still refused by the exists rule', function () {
    $foreign = Contact::factory()->create();

    $component = Livewire::test(CreateTask::class)
        ->fillForm(['title' => 'Cross org', 'contact_id' => $foreign->id])
        ->call('create');

    expect($component->errors()->has('data.contact_id'))->toBeTrue()
        ->and(Task::query()->where('title', 'Cross org')->exists())->toBeFalse();
});

dataset('padded record key shapes', [
    'leading space' => [fn (int $id): string => ' '.$id],
    'trailing space' => [fn (int $id): string => $id.' '],
    'plus sign' => [fn (int $id): string => '+'.$id],
    'trailing newline' => [fn (int $id): string => $id."\n"],
]);

test('an id the integer rule accepts but the state cast would drop is refused on the locked-contact quick task', function (Closure $shape) {
    Livewire::test(ViewContact::class, ['record' => $this->jane->id])
        ->mountAction('quickTask')
        ->set('mountedActions.0.data.title', 'Padded')
        ->set('mountedActions.0.data.contact_id', $shape($this->jane->id))
        ->callMountedAction()
        ->assertHasActionErrors(['contact_id' => RecordKeyIsReadable::class]);

    expect(Task::query()->where('title', 'Padded')->exists())->toBeFalse();
})->with('padded record key shapes');

test('an id the integer rule accepts but the state cast would drop is refused on the task form', function (Closure $shape) {
    $component = Livewire::test(CreateTask::class)
        ->fillForm(['title' => 'Padded', 'type' => TaskType::FollowUp])
        ->set('data.contact_id', $shape($this->jane->id))
        ->call('create');

    expect($component->errors()->get('data.contact_id')[0] ?? '')->toContain('must be an integer')
        ->and(Task::query()->where('title', 'Padded')->exists())->toBeFalse();
})->with('padded record key shapes');

test('an id the integer rule accepts but the state cast would drop is refused in a multiple select', function (Closure $shape) {
    $tag = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);

    $component = Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Padded', 'email' => 'padded@example.com'])
        ->set('data.tags', [$shape($tag->id)])
        ->call('create');

    expect($component->errors()->get('data.tags.0')[0] ?? '')->toContain('must be an integer')
        ->and(Contact::query()->where('email', 'padded@example.com')->exists())->toBeFalse();
})->with('padded record key shapes');

test('an id the integer rule accepts but the state cast would drop is refused on the optional deal company', function (Closure $shape) {
    $acme = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme']);

    $component = Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Padded', 'stage' => DealStage::Lead])
        ->set('data.company_id', $shape($acme->id))
        ->call('create');

    expect($component->errors()->get('data.company_id')[0] ?? '')->toContain('must be an integer')
        ->and(Deal::query()->where('title', 'Padded')->exists())->toBeFalse();
})->with('padded record key shapes');

dataset('scalar instead of a list', [
    'numeric string' => [fn (int $id): string => (string) $id],
    'integer' => [fn (int $id): int => $id],
    'json list string' => [fn (int $id): string => '["'.$id.'"]'],
]);

test('a scalar instead of a list is refused on the tags multiple select', function (Closure $shape) {
    $tag = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);

    $component = Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Scalar tags', 'email' => 'scalar-tags@example.com'])
        ->set('data.tags', $shape($tag->id))
        ->call('create');

    expect($component->errors()->has('data.tags'))->toBeTrue()
        ->and(Contact::query()->where('email', 'scalar-tags@example.com')->exists())->toBeFalse();
})->with('scalar instead of a list');

test('a scalar instead of a list is refused on the companies multiple select', function (Closure $shape) {
    $acme = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme']);

    $component = Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Scalar companies', 'email' => 'scalar-companies@example.com'])
        ->set('data.companies', $shape($acme->id))
        ->call('create');

    expect($component->errors()->has('data.companies'))->toBeTrue()
        ->and(Contact::query()->where('email', 'scalar-companies@example.com')->exists())->toBeFalse();
})->with('scalar instead of a list');

test('a real list of ids still attaches the tags and companies', function () {
    $tag = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);
    $acme = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme']);

    Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'List', 'email' => 'list@example.com'])
        ->set('data.tags', [(string) $tag->id])
        ->set('data.companies', [(string) $acme->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $contact = Contact::query()->where('email', 'list@example.com')->firstOrFail();

    expect($contact->tags()->pluck('tags.id')->all())->toBe([$tag->id])
        ->and($contact->companies()->pluck('companies.id')->all())->toBe([$acme->id]);
});
