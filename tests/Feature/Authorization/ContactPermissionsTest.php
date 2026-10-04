<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Jobs\ProcessContactImportJob;
use App\Models\Contact;
use App\Models\User;
use App\Policies\ContactPolicy;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann']);

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };
});

describe('a viewer', function () {
    beforeEach(function () {
        ($this->actAs)(OrganizationRole::Viewer);
    });

    it('sees the contacts and a contact', function () {
        $this->get(ContactResource::getUrl('index'))->assertOk();
        $this->get(ContactResource::getUrl('view', ['record' => $this->contact]))->assertOk();
    });

    it('has no create, import, delete or bulk action, and can still download the template', function () {
        Livewire::test(ListContacts::class)
            ->assertSuccessful()
            ->assertActionHidden(CreateAction::class)
            ->assertActionHidden('importContacts')
            ->assertActionVisible('downloadTemplate')
            ->assertTableActionHidden(DeleteAction::class, $this->contact)
            ->assertTableActionHidden('logActivity', $this->contact)
            ->assertTableActionHidden('addTask', $this->contact)
            ->assertTableBulkActionHidden('changeStatus')
            ->assertTableBulkActionHidden('addTags')
            ->assertTableBulkActionHidden(DeleteBulkAction::class);
    });

    it('cannot open the create or edit pages', function () {
        $this->get(ContactResource::getUrl('create'))->assertForbidden();
        $this->get(ContactResource::getUrl('edit', ['record' => $this->contact]))->assertForbidden();
    });

    it('has no action on the contact page', function () {
        Livewire::test(ViewContact::class, ['record' => $this->contact->getRouteKey()])
            ->assertActionHidden('logActivity')
            ->assertActionHidden('quickTask')
            ->assertActionHidden('edit');
    });

    it('cannot import by calling the action anyway', function () {
        Storage::fake('local');
        Queue::fake();

        Livewire::test(ListContacts::class)
            ->call('mountAction', 'importContacts', [], [])
            ->assertActionNotMounted()
            ->set('mountedActions.0.data.file', UploadedFile::fake()->create('c.csv', 10, 'text/csv'))
            ->call('callMountedAction');

        Queue::assertNotPushed(ProcessContactImportJob::class);
    });
});

describe('a member', function () {
    beforeEach(function () {
        ($this->actAs)(OrganizationRole::Member);
    });

    it('creates and edits contacts', function () {
        Livewire::test(CreateContact::class)
            ->fillForm(['name' => 'Bob', 'email' => 'bob@example.test'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(EditContact::class, ['record' => $this->contact->getRouteKey()])
            ->fillForm(['name' => 'Ann B.'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->contact->fresh()->name)->toBe('Ann B.')
            ->and(Contact::where('email', 'bob@example.test')->exists())->toBeTrue();
    });

    it('has the editing actions but not the destructive ones or the import', function () {
        Livewire::test(ListContacts::class)
            ->assertActionVisible(CreateAction::class)
            ->assertActionHidden('importContacts')
            ->assertTableActionVisible('logActivity', $this->contact)
            ->assertTableActionVisible('addTask', $this->contact)
            ->assertTableActionHidden(DeleteAction::class, $this->contact)
            ->assertTableBulkActionVisible('changeStatus')
            ->assertTableBulkActionVisible('addTags')
            ->assertTableBulkActionHidden(DeleteBulkAction::class);

        Livewire::test(EditContact::class, ['record' => $this->contact->getRouteKey()])
            ->assertActionHidden(DeleteAction::class)
            ->assertActionHidden(RestoreAction::class);
    });

    it('can change the status of contacts in bulk but not delete them', function () {
        Livewire::test(ListContacts::class)
            ->callTableBulkAction('changeStatus', [$this->contact], ['status' => 'lead'])
            ->assertNotified();

        Livewire::test(ListContacts::class)
            ->set('selectedTableRecords', [(string) $this->contact->getKey()])
            ->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        expect($this->contact->fresh()->trashed())->toBeFalse();
    });

    it('cannot delete a contact by calling the action anyway', function () {
        Livewire::test(EditContact::class, ['record' => $this->contact->getRouteKey()])
            ->call('mountAction', 'delete', [], [])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        expect($this->contact->fresh()->trashed())->toBeFalse();
    });
});

describe('an admin', function () {
    beforeEach(function () {
        ($this->actAs)(OrganizationRole::Admin);
    });

    it('imports, deletes in bulk and restores', function () {
        Storage::fake('local');
        Queue::fake();

        Livewire::test(ListContacts::class)
            ->assertActionVisible('importContacts')
            ->callAction('importContacts', ['file' => UploadedFile::fake()->create('c.csv', 10, 'text/csv')]);

        Queue::assertPushed(ProcessContactImportJob::class, 1);

        Livewire::test(ListContacts::class)
            ->assertTableBulkActionVisible(DeleteBulkAction::class)
            ->callTableBulkAction(DeleteBulkAction::class, [$this->contact]);

        expect($this->contact->fresh()->trashed())->toBeTrue();

        Livewire::test(EditContact::class, ['record' => $this->contact->getRouteKey()])
            ->assertActionVisible(RestoreAction::class);
    });
});

it('refuses the contacts of an organization the user does not belong to', function () {
    $stranger = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->actingAs($stranger);
    Filament::setTenant($stranger->personalOrganization());

    $this->get(ContactResource::getUrl('edit', ['record' => $this->contact]))->assertNotFound();
});

class DenyDeletingProtectedContactsPolicy extends ContactPolicy
{
    public function delete(User $user, Model $record): bool
    {
        return $record->name !== 'Protected' && parent::delete($user, $record);
    }
}

it('checks every record of a bulk delete', function () {
    ($this->actAs)(OrganizationRole::Admin);
    $protected = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Protected']);
    Gate::policy(Contact::class, DenyDeletingProtectedContactsPolicy::class);

    Livewire::test(ListContacts::class)
        ->callTableBulkAction(DeleteBulkAction::class, [$this->contact, $protected]);

    expect($this->contact->fresh()->trashed())->toBeTrue()
        ->and($protected->fresh()->trashed())->toBeFalse();
});
