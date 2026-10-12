<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Exceptions\UsedInSegmentsException;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Segments\Pages\SegmentRuleEngine;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tags\TagResource;
use App\Jobs\SyncSegmentMembership;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContactImportService;
use App\Services\Segments\SegmentConditionDescriber;
use App\Services\Segments\SegmentFieldCatalog;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->vip = Tag::factory()->for($this->org)->create(['name' => 'VIP']);
    $this->contact = Contact::factory()->for($this->org)->withTags([$this->vip->id])->create();
});

function vipTagSegment(Tag $tag): Segment
{
    return Segment::factory()->for(test()->org)->published()->withRules([
        new SegmentRuleData('rule-1', 'VIPs', [
            new SegmentConditionData('condition-1', SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]),
        ]),
    ])->create();
}

describe('deleting and restoring', function () {
    it('soft deletes a tag from the list, keeping it attached to contacts but hidden', function () {
        Livewire::test(ListTags::class)
            ->callAction(TestAction::make('delete')->table($this->vip))
            ->assertNotified()
            ->assertCanNotSeeTableRecords([$this->vip]);

        $this->assertSoftDeleted($this->vip);
        $this->assertDatabaseHas('contact_tag', ['contact_id' => $this->contact->id, 'tag_id' => $this->vip->id]);
        expect($this->contact->tags()->count())->toBe(0);
    });

    it('lists trashed tags and restores them on every contact', function () {
        $this->vip->delete();

        Livewire::test(ListTags::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$this->vip])
            ->callAction(TestAction::make('restore')->table($this->vip))
            ->assertNotified();

        $this->assertNotSoftDeleted($this->vip);
        expect($this->contact->tags()->pluck('tags.id')->all())->toBe([$this->vip->id]);
    });

    it('soft deletes and restores tags in bulk', function () {
        $other = Tag::factory()->for($this->org)->create();

        Livewire::test(ListTags::class)
            ->selectTableRecords([$this->vip, $other])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertNotified();

        $this->assertSoftDeleted($this->vip);
        $this->assertSoftDeleted($other);

        Livewire::test(ListTags::class)
            ->filterTable('trashed', false)
            ->selectTableRecords([$this->vip, $other])
            ->callAction(TestAction::make('restore')->table()->bulk());

        $this->assertNotSoftDeleted($this->vip);
        $this->assertNotSoftDeleted($other);
    });

    it('opens a trashed tag to restore it', function () {
        $this->vip->delete();

        Livewire::test(EditTag::class, ['record' => $this->vip->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('delete')
            ->callAction('restore');

        $this->assertNotSoftDeleted($this->vip);
    });

    it('cannot be force deleted', function () {
        $this->vip->delete();

        Livewire::test(ListTags::class)
            ->filterTable('trashed', false)
            ->assertTableActionDoesNotExist('forceDelete')
            ->assertTableBulkActionDoesNotExist('forceDelete');

        Livewire::test(EditTag::class, ['record' => $this->vip->getRouteKey()])
            ->assertActionDoesNotExist('forceDelete');

        expect(fn () => $this->vip->forceDelete())->toThrow(LogicException::class);
        $this->assertSoftDeleted($this->vip);
    });

    it('explains that a trashed tag may hold the requested name', function () {
        $this->vip->delete();

        Livewire::test(CreateTag::class)
            ->fillForm(['name' => 'VIP'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique'])
            ->assertSee('check the &quot;Trashed&quot; filter', false);
    });

    it('cannot open a trashed tag of another organization', function () {
        $foreign = Tag::factory()->create();
        $foreign->delete();

        $this->get(TagResource::getUrl('edit', ['record' => $foreign]))->assertNotFound();
    });
});

describe('contacts', function () {
    it('keeps trashed tags attached when the contact form is saved', function () {
        $lead = Tag::factory()->for($this->org)->create(['name' => 'Lead']);
        $this->vip->delete();

        Livewire::test(EditContact::class, ['record' => $this->contact->getRouteKey()])
            ->assertSchemaStateSet(['tags' => []])
            ->fillForm(['tags' => [$lead->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->contact->tags()->pluck('tags.id')->all())->toBe([$lead->id]);

        $this->vip->restore();

        expect($this->contact->tags()->orderBy('name')->pluck('name')->all())->toBe(['Lead', 'VIP']);
    });

    it('does not offer trashed tags in filters and bulk actions', function () {
        $active = Tag::factory()->for($this->org)->create(['name' => 'Active']);
        $this->vip->delete();

        Livewire::test(ListContacts::class)
            ->assertFormFieldExists('tags.values', 'tableFiltersForm', fn ($select): bool => $select->getOptions() === [$active->id => 'Active'])
            ->mountAction(TestAction::make('addTags')->table()->bulk())
            ->assertFormFieldExists('tags', fn ($field): bool => $field->getOptions() === [$active->id => 'Active']);
    });

    it('restores a trashed tag named in an import instead of duplicating it', function () {
        $this->vip->delete();

        $result = (new ContactImportService($this->org->id))->processRow([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '',
            'tags' => 'VIP;Partner',
        ], []);

        expect($result['success'])->toBeTrue();
        $this->assertNotSoftDeleted($this->vip);
        expect(Tag::query()->where('organization_id', $this->org->id)->pluck('name')->sort()->values()->all())->toBe(['Partner', 'VIP'])
            ->and(Contact::query()->firstWhere('email', 'jane@example.com')->tags()->orderBy('name')->pluck('name')->all())->toBe(['Partner', 'VIP']);
    });

    it('hides trashed tags from the Inertia tags page', function () {
        $this->vip->delete();
        Tag::factory()->for($this->org)->create(['name' => 'Alpha']);

        $this->get(route('app.tags.index', $this->org))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tags', 1)->where('tags.0.name', 'Alpha'));
    });
});

describe('segments', function () {
    it('cannot delete a tag used in segment conditions', function () {
        vipTagSegment($this->vip);

        expect(fn () => $this->vip->delete())->toThrow(UsedInSegmentsException::class);
        $this->assertNotSoftDeleted($this->vip);
    });

    it('ignores trashed tags in conditions saved before they were deleted, and resyncs when they are restored', function () {
        $this->vip->delete();
        $segment = vipTagSegment($this->vip);
        SyncSegmentMembership::dispatchSync($segment->id);

        expect($segment->contacts()->count())->toBe(0);

        $this->vip->restore();

        expect($segment->contacts()->count())->toBe(1);
    });

    it('only resyncs the published segments that use tag conditions when a tag is restored', function () {
        $this->vip->delete();
        $tagSegment = vipTagSegment($this->vip);
        $unpublished = vipTagSegment($this->vip);
        $unpublished->update(['is_published' => false]);
        Segment::factory()->for($this->org)->published()->withRules([
            new SegmentRuleData('rule-1', 'Names', [
                SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::IsNotBlank),
            ]),
        ])->create();
        Queue::fake();

        $this->vip->restore();

        Queue::assertPushed(SyncSegmentMembership::class, 1);
        Queue::assertPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => $job->segmentId === $tagSegment->id);
    });

    it('marks trashed tags in condition sentences and options', function () {
        $this->vip->delete();
        $segment = vipTagSegment($this->vip);
        $active = Tag::factory()->for($this->org)->create(['name' => 'Active']);
        Tag::factory()->for($this->org)->create(['name' => 'Gone'])->delete();

        $describer = new SegmentConditionDescriber(SegmentFieldCatalog::forOrganization($this->org->id));
        expect(strip_tags($describer->describe($segment->workingRules()->sole()->conditions[0])->toHtml()))
            ->toBe('If the contact has any of VIP (deleted)');

        Livewire::test(SegmentRuleEngine::class, ['record' => $segment->getRouteKey()])
            ->mountAction(TestAction::make('editCondition')->arguments(['rule' => 'rule-1', 'condition' => 'condition-1']))
            ->assertFormFieldExists('values', fn ($field): bool => $field->getOptions() === [
                $active->id => 'Active',
                $this->vip->id => 'VIP (deleted)',
            ]);
    });
});
