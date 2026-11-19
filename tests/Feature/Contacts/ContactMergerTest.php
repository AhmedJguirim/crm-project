<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MergeSide;
use App\Enums\OrganizationRole;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Exceptions\ContactMergeRefused;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Jobs\SyncSegmentMembership;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Contacts\ContactMerger;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

const MERGE_STALE = 'One of these contacts was deleted or merged in the meantime. Refresh the page and try again.';
const MERGE_OUT_OF_DATE = 'The merge form is out of date. Refresh the page and try again.';

/**
 * Everything a refused merge must leave alone, for the two contacts: their attributes, the contact_id of every linked
 * row, the pivot rows and the trashed state.
 *
 * @return array<string, mixed>
 */
function mergeWorldState(Contact ...$contacts): array
{
    $ids = array_map(fn (Contact $contact): int => $contact->id, $contacts);

    return [
        'contacts' => Contact::withTrashed()->withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get()->map->getAttributes()->all(),
        'activities' => Activity::withoutGlobalScopes()->orderBy('id')->pluck('contact_id', 'id')->all(),
        'deals' => Deal::withoutGlobalScopes()->orderBy('id')->pluck('contact_id', 'id')->all(),
        'tasks' => Task::withTrashed()->withoutGlobalScopes()->orderBy('id')->pluck('contact_id', 'id')->all(),
        'invoices' => Invoice::withoutGlobalScopes()->orderBy('id')->pluck('contact_id', 'id')->all(),
        'tag_links' => DB::table('contact_tag')->orderBy('contact_id')->orderBy('tag_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'company_links' => DB::table('company_contact')->orderBy('contact_id')->orderBy('company_id')->get()->map(fn (object $row): array => [$row->contact_id, $row->company_id])->all(),
    ];
}

function mergeMemberIds(Segment $segment): array
{
    return $segment->contacts()->pluck('contacts.id')->sort()->values()->all();
}

beforeEach(function () {
    $this->travelTo('2026-11-19 10:00:00');

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->userWith = function (OrganizationRole $role): User {
        $user = User::factory()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);

        return $user;
    };

    $this->admin = ($this->userWith)(OrganizationRole::Admin);
    $this->member = ($this->userWith)(OrganizationRole::Member);
    $this->viewer = ($this->userWith)(OrganizationRole::Viewer);

    $this->survivor = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Ada Lovelace',
        'email' => 'ada@acme.test',
        'phone' => null,
        'status' => ContactStatus::Lead,
        'owner_id' => $this->member->id,
    ]);
    $this->loser = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Ada L.',
        'email' => 'ada.l@acme.test',
        'phone' => '+33 6 12 34 56 78',
        'status' => ContactStatus::ActiveClient,
        'owner_id' => $this->admin->id,
    ]);

    $this->merge = fn (array $choices = [], ?User $by = null, ?Contact $survivor = null, ?Contact $loser = null): Contact => app(ContactMerger::class)->merge(
        $survivor ?? $this->survivor,
        $loser ?? $this->loser,
        $choices,
        $by ?? $this->admin,
    );
});

describe('linked rows', function () {
    it('moves every linked row to the survivor', function () {
        $paid = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id, 'status' => InvoiceStatus::Paid, 'amount' => '250.00']);
        $draft = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id, 'status' => InvoiceStatus::Draft]);
        Activity::factory()->count(2)->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        Activity::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->survivor->id]);
        Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        Task::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        Task::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id, 'deleted_at' => now()]);
        $before = $paid->fresh()->only(['status', 'amount', 'amount_paid', 'paid_at', 'currency', 'exchange_rate']);

        ($this->merge)();

        $count = fn (string $model, int $contactId): int => $model::query()->when($model === Task::class, fn ($query) => $query->withTrashed())->where('contact_id', $contactId)->count();

        expect($count(Activity::class, $this->survivor->id))->toBe(3)
            ->and($count(Deal::class, $this->survivor->id))->toBe(1)
            ->and($count(Task::class, $this->survivor->id))->toBe(2)
            ->and($count(Invoice::class, $this->survivor->id))->toBe(2)
            ->and($count(Activity::class, $this->loser->id))->toBe(0)
            ->and($count(Deal::class, $this->loser->id))->toBe(0)
            ->and($count(Task::class, $this->loser->id))->toBe(0)
            ->and($count(Invoice::class, $this->loser->id))->toBe(0)
            ->and(Task::onlyTrashed()->where('contact_id', $this->survivor->id)->count())->toBe(1)
            ->and($paid->fresh()->only(['status', 'amount', 'amount_paid', 'paid_at', 'currency', 'exchange_rate']))->toEqual($before)
            ->and($paid->fresh()->contact->name)->toBe('Ada Lovelace')
            ->and($draft->fresh()->contact_id)->toBe($this->survivor->id);
    });

    it('does not touch the rows of another contact or of another organization', function () {
        $bystander = Contact::factory()->create(['organization_id' => $this->org->id]);
        $bystanderActivity = Activity::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $bystander->id]);
        $otherOrg = Organization::factory()->create();
        $foreignActivity = Activity::factory()->create(['organization_id' => $otherOrg->id]);
        $foreignContactId = $foreignActivity->contact_id;

        ($this->merge)();

        expect($bystanderActivity->fresh()->contact_id)->toBe($bystander->id)
            ->and($foreignActivity->fresh()->contact_id)->toBe($foreignContactId);
    });

    it('makes tags and companies a union without duplicates', function () {
        $vip = Tag::factory()->create(['organization_id' => $this->org->id]);
        $lead = Tag::factory()->create(['organization_id' => $this->org->id]);
        $old = Tag::factory()->create(['organization_id' => $this->org->id]);
        $globex = Company::factory()->create(['organization_id' => $this->org->id]);
        $initech = Company::factory()->create(['organization_id' => $this->org->id]);
        $this->loser->tags()->attach([$vip->id, $lead->id, $old->id]);
        $this->loser->companies()->attach([$globex->id, $initech->id]);
        $this->survivor->tags()->attach($lead->id);
        $this->survivor->companies()->attach($globex->id);
        $old->delete();
        $initech->delete();

        ($this->merge)();

        $tagRows = DB::table('contact_tag')->where('contact_id', $this->survivor->id)->pluck('tag_id')->sort()->values()->all();
        $companyRows = DB::table('company_contact')->where('contact_id', $this->survivor->id)->pluck('company_id')->sort()->values()->all();

        expect($tagRows)->toBe(collect([$vip->id, $lead->id, $old->id])->sort()->values()->all())
            ->and($companyRows)->toBe(collect([$globex->id, $initech->id])->sort()->values()->all())
            ->and(DB::table('contact_tag')->where('contact_id', $this->loser->id)->count())->toBe(3)
            ->and(DB::table('company_contact')->where('contact_id', $this->loser->id)->count())->toBe(2);
    });
});

describe('values', function () {
    it('takes the survivor first, else the loser', function () {
        $result = ($this->merge)();

        expect($result->name)->toBe('Ada Lovelace')
            ->and($result->email)->toBe('ada@acme.test')
            ->and($result->phone)->toBe('+33 6 12 34 56 78')
            ->and($result->status)->toBe(ContactStatus::Lead)
            ->and($result->owner_id)->toBe($this->member->id);
    });

    it('takes the loser value of the chosen fields, the owner included', function () {
        $result = ($this->merge)([
            'name' => MergeSide::Loser,
            'status' => MergeSide::Loser,
            'owner_id' => MergeSide::Loser,
            'phone' => MergeSide::Survivor,
        ]);

        expect($result->name)->toBe('Ada L.')
            ->and($result->status)->toBe(ContactStatus::ActiveClient)
            ->and($result->owner_id)->toBe($this->admin->id)
            ->and($result->email)->toBe('ada@acme.test')
            ->and($result->phone)->toBeNull();
    });

    it('takes a blank value when the chosen side is blank', function () {
        $this->loser->forceFill(['lead_source' => null])->save();

        $result = ($this->merge)(['phone' => MergeSide::Survivor, 'owner_id' => MergeSide::Survivor]);

        expect($result->phone)->toBeNull()
            ->and($result->owner_id)->toBe($this->member->id);
    });

    it('lets the survivor take the email of the loser', function () {
        $result = ($this->merge)(['email' => MergeSide::Loser]);

        expect($result->email)->toBe('ada.l@acme.test')
            ->and(Contact::withTrashed()->find($this->loser->id)->email)->toBeNull()
            ->and(Contact::withTrashed()->find($this->loser->id)->merge_snapshot['loser']['email'])->toBe('ada.l@acme.test');
    });

    it('takes the email of the loser when the survivor has none', function () {
        $this->survivor->forceFill(['email' => null])->save();

        $result = ($this->merge)();

        expect($result->email)->toBe('ada.l@acme.test')
            ->and(Contact::withTrashed()->find($this->loser->id)->email)->toBeNull();
    });

    it('keeps the email of the loser on the loser when the survivor keeps its own', function () {
        ($this->merge)();

        expect(Contact::withTrashed()->find($this->loser->id)->email)->toBe('ada.l@acme.test');
    });

    it('moves a unique custom field value without a duplicate error', function () {
        $vat = CustomField::factory()->text()->create(['organization_id' => $this->org->id, 'name' => 'VAT number', 'unique' => true]);
        $notes = CustomField::factory()->text()->create(['organization_id' => $this->org->id, 'name' => 'Notes', 'unique' => false]);
        $this->loser->forceFill(['custom_field_values' => [$vat->key => 'FR123', $notes->key => 'from L']])->save();

        $result = ($this->merge)();

        $loser = Contact::withTrashed()->find($this->loser->id);

        expect($result->custom_field_values)->toEqual([$vat->key => 'FR123', $notes->key => 'from L'])
            ->and($loser->custom_field_values)->toEqual([$notes->key => 'from L'])
            ->and($loser->merge_snapshot['loser']['custom_field_values'])->toEqual([$vat->key => 'FR123', $notes->key => 'from L']);
    });

    it('lets the survivor take a chosen custom value and keeps the other keys of the survivor', function () {
        $plan = CustomField::factory()->text()->create(['organization_id' => $this->org->id, 'name' => 'Plan', 'unique' => false]);
        $site = CustomField::factory()->text()->create(['organization_id' => $this->org->id, 'name' => 'Site', 'unique' => false]);
        $this->survivor->forceFill(['custom_field_values' => [$plan->key => 'basic', $site->key => 'ada.test', 'orphan' => 'kept']])->save();
        $this->loser->forceFill(['custom_field_values' => [$plan->key => 'pro', $site->key => 'ignored']])->save();

        $result = ($this->merge)(['custom.'.$plan->key => MergeSide::Loser]);

        expect($result->custom_field_values)->toEqual([$plan->key => 'pro', $site->key => 'ada.test', 'orphan' => 'kept']);
    });

    it('applies the default rule to the value of a deleted custom field', function () {
        $gone = CustomField::factory()->text()->create(['organization_id' => $this->org->id, 'name' => 'Gone', 'unique' => false]);
        $this->loser->forceFill(['custom_field_values' => [$gone->key => 'from L']])->save();
        $gone->delete();

        $result = ($this->merge)();

        expect($result->custom_field_values)->toEqual([$gone->key => 'from L']);
    });

    it('refuses to choose the value of a deleted custom field', function () {
        $gone = CustomField::factory()->text()->create(['organization_id' => $this->org->id, 'name' => 'Gone', 'unique' => false]);
        $gone->delete();
        $state = mergeWorldState($this->survivor, $this->loser);

        expect(fn () => ($this->merge)(['custom.'.$gone->key => MergeSide::Loser]))->toThrow(ContactMergeRefused::class, MERGE_OUT_OF_DATE);
        expect(mergeWorldState($this->survivor, $this->loser))->toEqual($state);
    });
});

describe('the loser', function () {
    it('is soft-deleted, linked and snapshotted', function () {
        $tag = Tag::factory()->create(['organization_id' => $this->org->id]);
        $company = Company::factory()->create(['organization_id' => $this->org->id]);
        $this->loser->tags()->attach($tag->id);
        $this->loser->companies()->attach($company->id);
        $activities = Activity::factory()->count(2)->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        $deal = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        $task = Task::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        $trashedTask = Task::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id, 'deleted_at' => now()]);
        $invoice = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);

        $result = ($this->merge)(['name' => MergeSide::Loser]);

        $loser = Contact::withTrashed()->find($this->loser->id);

        expect($result->name)->toBe('Ada L.')
            ->and($result->is($this->survivor))->toBeTrue()
            ->and($loser->trashed())->toBeTrue()
            ->and($loser->merged_into_id)->toBe($this->survivor->id)
            ->and($loser->merged_by_id)->toBe($this->admin->id)
            ->and($loser->merged_at->toDateTimeString())->toBe('2026-11-19 10:00:00')
            ->and($loser->isMerged())->toBeTrue()
            ->and($loser->mergedInto->is($this->survivor))->toBeTrue()
            ->and($loser->mergedBy->is($this->admin))->toBeTrue()
            ->and($loser->merge_snapshot)->toEqual([
                'survivor_id' => $this->survivor->id,
                'loser' => [
                    'name' => 'Ada L.',
                    'email' => 'ada.l@acme.test',
                    'phone' => '+33 6 12 34 56 78',
                    'status' => 'active_client',
                    'lead_source' => null,
                    'owner_id' => $this->admin->id,
                    'custom_field_values' => [],
                ],
                'survivor_before' => [
                    'name' => 'Ada Lovelace',
                    'email' => 'ada@acme.test',
                    'phone' => null,
                    'status' => 'lead',
                    'lead_source' => null,
                    'owner_id' => $this->member->id,
                    'custom_field_values' => [],
                ],
                'moved' => [
                    'activities' => $activities->pluck('id')->sort()->values()->all(),
                    'deals' => [$deal->id],
                    'tasks' => collect([$task->id, $trashedTask->id])->sort()->values()->all(),
                    'invoices' => [$invoice->id],
                ],
                'added_to_survivor' => ['tags' => [$tag->id], 'companies' => [$company->id]],
            ]);
    });

    it('can not be loaded through the forged merge columns of a form', function () {
        $contact = new Contact;
        $contact->fill(['name' => 'Forged', 'merged_into_id' => $this->survivor->id, 'merged_by_id' => $this->admin->id, 'merged_at' => now(), 'merge_snapshot' => ['x' => 1]]);

        expect($contact->merged_into_id)->toBeNull()
            ->and($contact->merged_by_id)->toBeNull()
            ->and($contact->merged_at)->toBeNull()
            ->and($contact->merge_snapshot)->toBeNull();
    });

    it('is never linked to itself', function () {
        expect(fn () => DB::transaction(fn () => DB::table('contacts')->where('id', $this->loser->id)->update(['merged_into_id' => $this->loser->id])))
            ->toThrow(QueryException::class, 'contacts_not_merged_into_itself_check');
    });
});

describe('segments', function () {
    it('has the right members after the merge', function (SegmentConditionType $type, SegmentOperator $operator, string $link) {
        $values = [];

        if ($link === 'tag') {
            $tag = Tag::factory()->create(['organization_id' => $this->org->id]);
            $this->loser->tags()->attach($tag->id);
            $values = ['values' => [$tag->id]];
        }

        if ($link === 'company') {
            $company = Company::factory()->create(['organization_id' => $this->org->id]);
            $this->loser->companies()->attach($company->id);
            $values = ['values' => [$company->id]];
        }

        if ($link === 'deal') {
            Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        }

        if ($link === 'activity') {
            Activity::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        }

        $segment = Segment::factory()->for($this->org)->published()->withRules([
            new SegmentRuleData('rule-1', 'Rule', [SegmentConditionData::make($type, null, $operator, $values)]),
        ])->create();
        SyncSegmentMembership::dispatchSync($segment->id);
        expect(mergeMemberIds($segment))->toBe([$this->loser->id]);

        ($this->merge)();

        expect(mergeMemberIds($segment))->toBe([$this->survivor->id]);
    })->with([
        'tag' => [SegmentConditionType::Tags, SegmentOperator::HasAnyOf, 'tag'],
        'company' => [SegmentConditionType::Company, SegmentOperator::BelongsToAnyOf, 'company'],
        'deal' => [SegmentConditionType::Deal, SegmentOperator::HasDeal, 'deal'],
        'activity' => [SegmentConditionType::Activity, SegmentOperator::HasHadActivity, 'activity'],
    ]);
});

describe('locking', function () {
    it('locks both rows in id order', function () {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        ($this->merge)();

        $locking = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from "contacts"') && str_contains($sql, 'for update'));

        expect($locking)->toHaveCount(1)
            ->and($locking->first())->toContain('order by "id"');
    });
});

describe('refusals', function () {
    it('refuses the same contact twice', function () {
        $state = mergeWorldState($this->survivor, $this->loser);

        expect(fn () => ($this->merge)([], null, $this->survivor, $this->survivor))->toThrow(ContactMergeRefused::class, 'Pick two different contacts to merge.');
        expect(mergeWorldState($this->survivor, $this->loser))->toEqual($state);
    });

    it('refuses a contact of another organization', function () {
        $globex = Organization::factory()->create();
        $globex->members()->attach($this->admin, ['role' => OrganizationRole::Admin->value]);
        $foreign = Contact::factory()->create(['organization_id' => $globex->id]);
        Activity::factory()->create(['organization_id' => $globex->id, 'contact_id' => $foreign->id]);
        $state = mergeWorldState($this->survivor, $foreign);

        expect(fn () => ($this->merge)([], null, $this->survivor, $foreign))->toThrow(ContactMergeRefused::class, 'Only contacts of the same organization can be merged.');
        expect(fn () => ($this->merge)([], null, $foreign, $this->survivor))->toThrow(ContactMergeRefused::class, 'Only contacts of the same organization can be merged.');
        expect(mergeWorldState($this->survivor, $foreign))->toEqual($state);
    });

    it('refuses a role that can not merge', function (string $who) {
        $user = $this->{$who};
        Activity::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        $state = mergeWorldState($this->survivor, $this->loser);

        expect(fn () => ($this->merge)([], $user))->toThrow(ContactMergeRefused::class, 'Only admins and the owner can merge contacts.');
        expect(mergeWorldState($this->survivor, $this->loser))->toEqual($state);
    })->with(['member', 'viewer']);

    it('refuses a user of another organization', function () {
        $stranger = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $state = mergeWorldState($this->survivor, $this->loser);

        expect(fn () => ($this->merge)([], $stranger))->toThrow(ContactMergeRefused::class, 'Only admins and the owner can merge contacts.');
        expect(mergeWorldState($this->survivor, $this->loser))->toEqual($state);
    });

    it('refuses a user removed from the organization after the page was loaded', function () {
        expect($this->admin->roleIn($this->org->id))->toBe(OrganizationRole::Admin);
        $this->org->members()->detach($this->admin->id);
        $state = mergeWorldState($this->survivor, $this->loser);

        expect(fn () => ($this->merge)())->toThrow(ContactMergeRefused::class, 'Only admins and the owner can merge contacts.');
        expect(mergeWorldState($this->survivor, $this->loser))->toEqual($state);
    });

    it('refuses a user demoted after the page was loaded', function () {
        expect($this->admin->roleIn($this->org->id))->toBe(OrganizationRole::Admin);
        $this->org->members()->updateExistingPivot($this->admin->id, ['role' => OrganizationRole::Viewer->value]);

        expect(fn () => ($this->merge)())->toThrow(ContactMergeRefused::class, 'Only admins and the owner can merge contacts.');
        expect(Contact::find($this->loser->id)?->trashed())->toBeFalse();
    });

    it('lets the owner merge', function () {
        ($this->merge)([], $this->owner);

        expect(Contact::withTrashed()->find($this->loser->id))->merged_into_id->toBe($this->survivor->id)
            ->and(Contact::withTrashed()->find($this->loser->id)->trashed())->toBeTrue();
    });

    it('refuses a forged choice', function (array $choices) {
        Activity::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        $state = mergeWorldState($this->survivor, $this->loser);

        expect(fn () => ($this->merge)($choices))->toThrow(ContactMergeRefused::class, MERGE_OUT_OF_DATE);
        expect(mergeWorldState($this->survivor, $this->loser))->toEqual($state);
    })->with([
        'organization_id' => [['organization_id' => MergeSide::Loser]],
        'merged_into_id' => [['merged_into_id' => MergeSide::Loser]],
        'deleted_at' => [['deleted_at' => MergeSide::Loser]],
        'a string, not a side' => [['name' => 'loser']],
        'a custom field that does not exist' => [['custom.does_not_exist' => MergeSide::Loser]],
        'a custom field of another organization' => [['custom.' => MergeSide::Loser]],
        'a list' => [['name' => [MergeSide::Loser]]],
        'null' => [['name' => null]],
    ]);

    it('refuses the custom field of another organization', function () {
        $other = Organization::factory()->create();
        $foreign = CustomField::factory()->text()->create(['organization_id' => $other->id, 'name' => 'Foreign']);

        expect(fn () => ($this->merge)(['custom.'.$foreign->key => MergeSide::Loser]))->toThrow(ContactMergeRefused::class, MERGE_OUT_OF_DATE);
    });

    it('refuses a stale pair', function (string $change) {
        $survivor = Contact::find($this->survivor->id);
        $loser = Contact::find($this->loser->id);
        $third = Contact::factory()->create(['organization_id' => $this->org->id]);

        match ($change) {
            'loser trashed' => Contact::find($this->loser->id)->delete(),
            'survivor trashed' => Contact::find($this->survivor->id)->delete(),
            'loser merged elsewhere' => Contact::whereKey($this->loser->id)->first()->forceFill(['merged_into_id' => $third->id])->save(),
            'survivor merged elsewhere' => Contact::whereKey($this->survivor->id)->first()->forceFill(['merged_into_id' => $third->id])->save(),
        };
        Activity::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        $state = mergeWorldState($this->survivor, $this->loser);

        expect(fn () => ($this->merge)([], null, $survivor, $loser))->toThrow(ContactMergeRefused::class, MERGE_STALE);
        expect(mergeWorldState($this->survivor, $this->loser))->toEqual($state);
    })->with(['loser trashed', 'survivor trashed', 'loser merged elsewhere', 'survivor merged elsewhere']);

    it('refuses a pair whose rows were forced out of the organization', function () {
        $survivor = Contact::find($this->survivor->id);
        $loser = Contact::find($this->loser->id);
        DB::table('contacts')->where('id', $this->loser->id)->update(['organization_id' => Organization::factory()->create()->id]);

        expect(fn () => ($this->merge)([], null, $survivor, $loser))->toThrow(ContactMergeRefused::class, MERGE_STALE);
    });

    it('refuses the same merge twice and changes nothing the second time', function () {
        Activity::factory()->count(2)->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        $survivor = Contact::find($this->survivor->id);
        $loser = Contact::find($this->loser->id);

        ($this->merge)([], null, $survivor, $loser);
        $state = mergeWorldState($this->survivor, $this->loser);

        expect(fn () => ($this->merge)([], null, $survivor, $loser))->toThrow(ContactMergeRefused::class, MERGE_STALE);
        expect(mergeWorldState($this->survivor, $this->loser))->toEqual($state)
            ->and(Activity::where('contact_id', $this->survivor->id)->count())->toBe(2);
    });

    it('refuses a merge into a contact that was itself merged', function () {
        $third = Contact::factory()->create(['organization_id' => $this->org->id]);
        ($this->merge)();

        expect(fn () => ($this->merge)([], null, $third, Contact::withTrashed()->find($this->loser->id)))->toThrow(ContactMergeRefused::class, MERGE_STALE);
    });

    it('rolls everything back when a write fails midway', function () {
        $paid = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id, 'status' => InvoiceStatus::Draft]);
        Activity::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->loser->id]);
        Deal::saving(function (): never {
            throw new RuntimeException('boom');
        });
        $state = mergeWorldState($this->survivor, $this->loser);

        expect(fn () => ($this->merge)())->toThrow(RuntimeException::class, 'boom');

        Deal::flushEventListeners();
        expect(mergeWorldState($this->survivor, $this->loser))->toEqual($state)
            ->and($paid->fresh()->contact_id)->toBe($this->loser->id);
    });
});

describe('restoring a merged contact', function () {
    it('is refused to every role', function () {
        ($this->merge)();
        $merged = Contact::withTrashed()->find($this->loser->id);
        $plain = Contact::factory()->create(['organization_id' => $this->org->id, 'deleted_at' => now()]);

        expect(Gate::forUser($this->admin)->allows('restore', $merged))->toBeFalse()
            ->and(Gate::forUser($this->owner)->allows('restore', $merged))->toBeFalse()
            ->and(Gate::forUser($this->admin)->allows('restore', $plain))->toBeTrue();
    });

    it('hides the restore action on the contact page', function () {
        ($this->merge)();
        $this->actingAs($this->admin);

        Livewire::test(EditContact::class, ['record' => $this->loser->id])
            ->assertActionHidden(RestoreAction::class);
    });

    it('shows the restore action of a contact that was only deleted', function () {
        $plain = Contact::factory()->create(['organization_id' => $this->org->id, 'deleted_at' => now()]);
        $this->actingAs($this->admin);

        Livewire::test(EditContact::class, ['record' => $plain->id])
            ->assertActionVisible(RestoreAction::class);
    });

    it('is skipped by the bulk restore, which restores the other contacts', function () {
        ($this->merge)();
        $merged = Contact::withTrashed()->find($this->loser->id);
        $plain = Contact::factory()->create(['organization_id' => $this->org->id, 'deleted_at' => now()]);
        $this->actingAs($this->admin);

        Livewire::test(ListContacts::class)
            ->filterTable('trashed', true)
            ->callTableBulkAction('restore', [$merged, $plain]);

        expect(Contact::withTrashed()->find($plain->id)->trashed())->toBeFalse()
            ->and(Contact::withTrashed()->find($this->loser->id)->trashed())->toBeTrue()
            ->and(Contact::withTrashed()->find($this->loser->id)->merged_into_id)->toBe($this->survivor->id);
    });
});
