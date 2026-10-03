<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Jobs\ProcessContactImportJob;
use App\Jobs\ResyncContactSegments;
use App\Jobs\SyncSegmentMembership;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

function taggedSegment(Tag $tag, bool $published = true): Segment
{
    return Segment::factory()->for(test()->org)->state(['is_published' => $published])->withRules([
        new SegmentRuleData('rule-1', 'Tagged', [
            SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]),
        ]),
    ])->create();
}

it('stops showing a segment as syncing when its sync job fails', function () {
    $segment = Segment::factory()->for($this->org)->published()->create(['is_syncing' => true]);

    (new SyncSegmentMembership($segment->id))->failed(new RuntimeException('Boom'));

    expect($segment->fresh()->is_syncing)->toBeFalse();
});

it('adds imported contacts to segments, including through tags created by the import', function () {
    Storage::fake('local');
    $vip = Tag::factory()->for($this->org)->create(['name' => 'VIP']);
    $segment = taggedSegment($vip);
    $path = 'contact-imports/test.csv';
    Storage::disk('local')->put($path, "name,email,phone,tags\nJohn Doe,john@example.com,,VIP\nJane Doe,jane@example.com,,\n");

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    expect($segment->contacts()->pluck('email')->all())->toBe(['john@example.com']);
});

it('queues a sync of the published segments once an import is processed', function () {
    Storage::fake('local');
    $published = taggedSegment(Tag::factory()->for($this->org)->create());
    taggedSegment(Tag::factory()->for($this->org)->create(), published: false);
    $path = 'contact-imports/test.csv';
    Storage::disk('local')->put($path, "name,email,phone,tags\nJohn Doe,john@example.com,,\n");
    Queue::fake([SyncSegmentMembership::class]);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    Queue::assertPushed(SyncSegmentMembership::class, 1);
    Queue::assertPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => $job->segmentId === $published->id);
});

it('adds a contact created from the form to segments, using its tags', function () {
    $vip = Tag::factory()->for($this->org)->create();
    $segment = taggedSegment($vip);

    Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'tags' => [$vip->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($segment->contacts()->pluck('email')->all())->toBe(['jane@example.com']);
});

it('re-evaluates a contact against every published segment of its organization only', function () {
    $leads = Segment::factory()->for($this->org)->published()->withRules([
        new SegmentRuleData('rule-1', 'Leads', [
            SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Lead->value]),
        ]),
    ])->create();
    $everyone = Segment::factory()->for($this->org)->published()->withRules([
        new SegmentRuleData('rule-1', 'Everyone', [
            SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::IsNotBlank),
        ]),
    ])->create();
    $draft = Segment::factory()->for($this->org)->withRules([
        new SegmentRuleData('rule-1', 'Everyone', [
            SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::IsNotBlank),
        ]),
    ])->create();

    $contact = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);

    expect($contact->segments()->pluck('segments.id')->sort()->values()->all())->toBe(collect([$leads->id, $everyone->id])->sort()->values()->all())
        ->and($draft->contacts()->count())->toBe(0);
});

it('does nothing for a contact that no longer exists', function () {
    Segment::factory()->for($this->org)->published()->create();

    expect(fn () => (new ResyncContactSegments(999999))->handle())->not->toThrow(Throwable::class);
});

it('keeps duplicate sync jobs of the same segment or contact from piling up', function () {
    expect((new SyncSegmentMembership(5))->uniqueId())->toBe('5')
        ->and((new ResyncContactSegments(7))->uniqueId())->toBe('7');
});

it('matches multi-select segments for imported contacts with empty parts in the cell', function () {
    Storage::fake('local');
    $field = CustomField::factory()->multiselect()->create(['organization_id' => $this->org->id, 'name' => 'Tech Stack', 'unique' => false, 'order' => 1, 'options' => [
        ['label' => 'laravel', 'value' => 'laravel'], ['label' => 'react', 'value' => 'react'],
    ]]);
    $segment = Segment::factory()->for($this->org)->published()->withRules([
        new SegmentRuleData('rule-1', 'React', [
            SegmentConditionData::make(SegmentConditionType::CustomField, $field->key, SegmentOperator::ContainsAnyOf, ['values' => ['react']]),
        ]),
    ])->create();
    $path = 'contact-imports/test.csv';
    Storage::disk('local')->put($path, "name,email,phone,tags,Tech Stack\nJohn Doe,john@example.com,,,laravel;;react\n");

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    expect($segment->contacts()->pluck('email')->all())->toBe(['john@example.com']);
});

it('puts a contact into a deal stage segment when its deal card is moved on the pipeline board', function () {
    $contact = Contact::factory()->for($this->org)->create();
    $deal = Deal::factory()->for($this->org)->for($contact)->create([
        'created_by' => $this->user->id,
        'stage' => DealStage::Negotiating,
        'status' => DealStatus::Open,
        'position' => '1000.0000000000',
    ]);
    $segment = Segment::factory()->for($this->org)->published()->withRules([
        new SegmentRuleData('rule-1', 'Won deals', [
            SegmentConditionData::make(SegmentConditionType::Deal, null, SegmentOperator::HasDeal, ['deal_stages' => [DealStage::Won->value]]),
        ]),
    ])->create();

    expect($segment->contacts()->count())->toBe(0);

    Livewire::test(DealPipeline::class)->call('moveCard', (string) $deal->id, DealStage::Won->value);

    expect($deal->fresh()->stage)->toBe(DealStage::Won)
        ->and($deal->fresh()->status)->toBe(DealStatus::Won)
        ->and($segment->contacts()->pluck('contacts.id')->all())->toBe([$contact->id]);
});
