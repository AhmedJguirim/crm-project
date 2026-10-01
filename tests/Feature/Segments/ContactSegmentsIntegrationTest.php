<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Widgets\ContactDetailsWidget;
use App\Filament\Resources\Segments\SegmentResource;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

function vipSegment(Tag $tag): Segment
{
    return Segment::factory()->for(test()->org)->published()->withRules([
        new SegmentRuleData('rule-1', 'VIPs', [
            SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]),
        ]),
    ])->create(['name' => 'VIP contacts']);
}

it('filters contacts by segment', function () {
    $segment = Segment::factory()->for($this->org)->published()->withRules([
        new SegmentRuleData('rule-1', 'Leads', [
            SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Lead->value]),
        ]),
    ])->create();
    $lead = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
    $partner = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Partner]);

    Livewire::test(ListContacts::class)
        ->filterTable('segments', [$segment->id])
        ->assertCanSeeTableRecords([$lead])
        ->assertCanNotSeeTableRecords([$partner]);
});

it('adds contacts to segments when tagged in bulk', function () {
    $tag = Tag::factory()->for($this->org)->create();
    $segment = vipSegment($tag);
    $contacts = Contact::factory()->for($this->org)->count(2)->create();

    Livewire::test(ListContacts::class)
        ->callTableBulkAction('addTags', $contacts, ['tags' => [$tag->id]]);

    expect($segment->contacts()->count())->toBe(2);
});

it('adds a contact to segments when tagged from the edit form', function () {
    $tag = Tag::factory()->for($this->org)->create();
    $segment = vipSegment($tag);
    $contact = Contact::factory()->for($this->org)->create();

    Livewire::test(EditContact::class, ['record' => $contact->getRouteKey()])
        ->fillForm(['tags' => [$tag->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($segment->contacts()->whereKey($contact)->exists())->toBeTrue();
});

it('shows the segments of a contact on its page', function () {
    $tag = Tag::factory()->for($this->org)->create();
    $segment = vipSegment($tag);
    $contact = Contact::factory()->for($this->org)->withTags([$tag->id])->create();
    $contact->segments()->syncWithoutDetaching([$segment->id]);

    Livewire::test(ContactDetailsWidget::class, ['record' => $contact])
        ->assertSee('Segments')
        ->assertSee('VIP contacts')
        ->assertSeeHtml(e(SegmentResource::getUrl('view', ['record' => $segment])));

    $this->get(ContactResource::getUrl('view', ['record' => $contact]))->assertSuccessful();
});

it('groups the audience resources in the navigation', function () {
    $this->get(SegmentResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Audience');
});
