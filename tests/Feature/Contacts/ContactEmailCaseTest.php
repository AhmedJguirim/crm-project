<?php

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\ContactImportService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->import = fn (string $name, string $email): array => (new ContactImportService($this->org->id))->processRow(['name' => $name, 'email' => $email], []);
});

describe('blank emails', function () {
    it('stores blanks as null in the model', function (?string $input, ?string $stored) {
        expect((new Contact(['email' => $input]))->email)->toBe($stored);
    })->with([
        'null' => [null, null],
        'empty' => ['', null],
        'spaces' => ['   ', null],
        'padded' => [' Ann@Example.COM ', 'ann@example.com'],
    ]);

    it('lets the database refuse a blank email', function () {
        DB::transaction(fn () => DB::table('contacts')->insert([
            'organization_id' => $this->org->id, 'name' => 'Blank', 'email' => '', 'custom_field_values' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]));
    })->throws(QueryException::class, 'contacts_email_not_blank_check');

    it('lets the database refuse a duplicate present email, allow many nulls, and scope uniqueness to the organization', function () {
        $row = fn (int $organizationId, string $name, ?string $email): array => [
            'organization_id' => $organizationId, 'name' => $name, 'email' => $email, 'custom_field_values' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ];
        $other = Organization::factory()->create();

        DB::table('contacts')->insert($row($this->org->id, 'First', 'dup@example.com'));

        $message = null;
        try {
            DB::transaction(fn () => DB::table('contacts')->insert($row($this->org->id, 'Second', 'dup@example.com')));
        } catch (QueryException $exception) {
            $message = $exception->getMessage();
        }

        expect($message)->toContain('contacts_organization_id_email_unique');

        DB::table('contacts')->insert($row($this->org->id, 'Null one', null));
        DB::table('contacts')->insert($row($this->org->id, 'Null two', null));
        DB::table('contacts')->insert($row($other->id, 'Other org', 'dup@example.com'));

        expect(DB::table('contacts')->whereNull('email')->where('organization_id', $this->org->id)->count())->toBe(2)
            ->and(DB::table('contacts')->where('email', 'dup@example.com')->count())->toBe(2);
    });
});

describe('the form', function () {
    it('stores the email trimmed and in lowercase', function () {
        Livewire::test(CreateContact::class)
            ->fillForm(['name' => 'Ann', 'email' => ' Ann@Example.COM '])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Contact::sole()->email)->toBe('ann@example.com');
    });

    it('refuses a duplicate that only differs by case', function () {
        Contact::factory()->for($this->org)->create(['email' => 'ann@example.com']);

        Livewire::test(CreateContact::class)
            ->fillForm(['name' => 'Ann', 'email' => 'Ann@Example.com'])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);

        expect(Contact::count())->toBe(1);
    });

    it('keeps the own email of an edited contact valid', function () {
        $contact = Contact::factory()->for($this->org)->create(['email' => 'ann@example.com']);

        Livewire::test(EditContact::class, ['record' => $contact->id])
            ->fillForm(['email' => 'ANN@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($contact->fresh()->email)->toBe('ann@example.com');
    });

    it('accepts the same email in another organization', function () {
        Contact::factory()->for(Organization::factory()->create())->create(['email' => 'ann@example.com']);

        Livewire::test(CreateContact::class)
            ->fillForm(['name' => 'Ann', 'email' => 'Ann@Example.com'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Contact::forOrganization($this->org->id)->sole()->email)->toBe('ann@example.com');
    });
});

describe('the import', function () {
    it('treats a duplicate that only differs by case as existing', function () {
        Contact::factory()->for($this->org)->create(['email' => 'ann@example.com']);

        $result = ($this->import)('Ann', 'Ann@Example.com');

        expect($result)->toBe(['success' => false, 'error' => "A contact with email 'ann@example.com' already exists."])
            ->and(Contact::count())->toBe(1);
    });

    it('stores new emails in lowercase', function () {
        $result = ($this->import)('Bob', 'Bob@Example.com');

        expect($result['success'])->toBeTrue()
            ->and(Contact::sole()->email)->toBe('bob@example.com');
    });

    it('reports a case-only duplicate of a deleted contact as deleted', function () {
        Contact::factory()->for($this->org)->create(['email' => 'ann@example.com'])->delete();

        $result = ($this->import)('Ann', 'ANN@example.com');

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toContain("A deleted contact with email 'ann@example.com' already exists");
    });
});

describe('the model and the database', function () {
    it('lowercases and trims on assignment and keeps null', function () {
        expect((new Contact(['email' => ' Mixed@Example.COM ']))->email)->toBe('mixed@example.com')
            ->and((new Contact(['email' => null]))->email)->toBeNull();
    });

    it('refuses a mixed-case email written without the model', function () {
        expect(fn () => DB::table('contacts')->insert([
            'organization_id' => $this->org->id,
            'name' => 'Mixed',
            'email' => 'Mixed@Example.com',
            'custom_field_values' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class, 'contacts_email_lowercase_check');
    });
});
