<?php

use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->acmeOwner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->globexOwner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->acme = $this->acmeOwner->personalOrganization();
    $this->globex = $this->globexOwner->personalOrganization();

    $this->customerNumber = CustomField::factory()->for($this->acme)->create(['name' => 'Customer number', 'type' => 'text', 'unique' => true]);

    $this->insertContact = fn ($organization, array $values, ?string $deletedAt = null) => DB::table('contacts')->insert([
        ...storedRowOf(Contact::factory()->make(['organization_id' => $organization->id, 'custom_field_values' => $values])),
        'deleted_at' => $deletedAt,
    ]);

    $this->notificationsOf = fn (User $user) => DatabaseNotification::where('notifiable_id', $user->id)->get();
});

it('tells the owner about duplicates in contact and company fields', function () {
    ($this->insertContact)($this->acme, [$this->customerNumber->key => 'C-9']);
    ($this->insertContact)($this->acme, [$this->customerNumber->key => 'C-9']);
    ($this->insertContact)($this->acme, [$this->customerNumber->key => 'C-1']);

    $type = CompanyType::factory()->create(['organization_id' => $this->acme->id]);
    $siret = CompanyCustomField::factory()->create(['organization_id' => $this->acme->id, 'company_type_id' => $type->id, 'name' => 'SIRET', 'type' => 'text', 'unique' => true]);

    foreach (range(1, 3) as $position) {
        DB::table('companies')->insert(storedRowOf(Company::factory()->make([
            'organization_id' => $this->acme->id,
            'company_type_id' => $type->id,
            'custom_field_values' => [$siret->key => '555'],
        ])));
    }

    Artisan::call('custom-fields:report-duplicates');

    $notifications = ($this->notificationsOf)($this->acmeOwner);

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]->data['title'])->toBe('Duplicate values in unique fields')
        ->and($notifications[0]->data['body'])->toContain('Customer number: 1 values used more than once')
        ->and($notifications[0]->data['body'])->toContain('SIRET: 1 values used more than once')
        ->and($notifications[0]->data['body'])->not->toContain('C-9')
        ->and($notifications[0]->data['body'])->not->toContain('555');
});

it('sends nothing when there are no duplicates', function () {
    ($this->insertContact)($this->acme, [$this->customerNumber->key => 'C-1']);
    ($this->insertContact)($this->acme, [$this->customerNumber->key => 'C-2']);

    Artisan::call('custom-fields:report-duplicates');

    expect(DatabaseNotification::count())->toBe(0);
});

it('reports each organization separately', function () {
    $globexField = CustomField::factory()->for($this->globex)->create(['name' => 'Badge', 'type' => 'text', 'unique' => true]);
    ($this->insertContact)($this->globex, [$globexField->key => 'B-1']);
    ($this->insertContact)($this->globex, [$globexField->key => 'B-1']);
    ($this->insertContact)($this->acme, [$this->customerNumber->key => 'C-1']);

    Artisan::call('custom-fields:report-duplicates');

    expect(($this->notificationsOf)($this->acmeOwner))->toHaveCount(0)
        ->and(($this->notificationsOf)($this->globexOwner))->toHaveCount(1)
        ->and(($this->notificationsOf)($this->globexOwner)[0]->data['body'])->toContain('Badge: 1 values used more than once');
});

it('does not compare the contacts of one organization with the values of another', function () {
    $sameKeyElsewhere = CustomField::factory()->for($this->globex)->create(['key' => $this->customerNumber->key, 'type' => 'text', 'unique' => true]);
    ($this->insertContact)($this->acme, [$this->customerNumber->key => 'C-1']);
    ($this->insertContact)($this->globex, [$sameKeyElsewhere->key => 'C-1']);

    Artisan::call('custom-fields:report-duplicates');

    expect(DatabaseNotification::count())->toBe(0);
});

it('ignores blank values and trashed records', function () {
    ($this->insertContact)($this->acme, [$this->customerNumber->key => '']);
    ($this->insertContact)($this->acme, [$this->customerNumber->key => '']);
    ($this->insertContact)($this->acme, []);
    ($this->insertContact)($this->acme, []);
    ($this->insertContact)($this->acme, [$this->customerNumber->key => 'C-1']);
    ($this->insertContact)($this->acme, [$this->customerNumber->key => 'C-1'], now()->toDateTimeString());

    Artisan::call('custom-fields:report-duplicates');

    expect(DatabaseNotification::count())->toBe(0);
});

it('ignores fields that are not unique', function () {
    $industry = CustomField::factory()->for($this->acme)->create(['type' => 'text', 'unique' => false]);
    ($this->insertContact)($this->acme, [$industry->key => 'Software']);
    ($this->insertContact)($this->acme, [$industry->key => 'Software']);

    Artisan::call('custom-fields:report-duplicates');

    expect(DatabaseNotification::count())->toBe(0);
});

it('is scheduled daily at 02:00 without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains($event->command, 'custom-fields:report-duplicates'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
