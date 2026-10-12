<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Jobs\ProcessContactImportJob;
use App\Jobs\ResyncContactSegments;
use App\Jobs\SyncSegmentMembership;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContactImportService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

function importCsv(string $content): string
{
    $path = 'contact-imports/test-'.uniqid().'.csv';
    Storage::disk('local')->put($path, $content);

    return $path;
}

function publishedOrDraftVipSegment(Tag $tag, bool $published = true): Segment
{
    return Segment::factory()->for(test()->org)->state(['is_published' => $published])->withRules([
        new SegmentRuleData('rule-1', 'VIP', [
            SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]),
        ]),
    ])->create();
}

describe('contact imports', function () {
    beforeEach(function () {
        $this->vip = Tag::factory()->for($this->org)->create(['name' => 'VIP']);
        $this->segments = [publishedOrDraftVipSegment($this->vip), publishedOrDraftVipSegment($this->vip)];
        publishedOrDraftVipSegment($this->vip, published: false);
    });

    it('queues one full sync per published segment and no per-contact resync', function () {
        Queue::fake([ResyncContactSegments::class, SyncSegmentMembership::class]);
        $path = importCsv("name,email,phone,tags\nA,a@example.com,,\nB,b@example.com,,\nC,c@example.com,,\n");

        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        expect(Contact::where('organization_id', $this->org->id)->count())->toBe(3);
        Queue::assertNotPushed(ResyncContactSegments::class);
        Queue::assertPushed(SyncSegmentMembership::class, 2);

        foreach ($this->segments as $segment) {
            Queue::assertPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => $job->segmentId === $segment->id);
        }
    });

    it('queues no sync when every row fails', function () {
        Queue::fake([ResyncContactSegments::class, SyncSegmentMembership::class]);
        $path = importCsv("name,email,phone,tags\n,missing-name@example.com,,\nNo Email,,,\nBad Email,not-an-email,,\n");

        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        expect(Contact::where('organization_id', $this->org->id)->count())->toBe(0);
        Queue::assertNotPushed(SyncSegmentMembership::class);
        Queue::assertNotPushed(ResyncContactSegments::class);
    });

    it('still adds imported contacts to the matching segments', function () {
        $path = importCsv("name,email,phone,tags\nA,a@example.com,,VIP\nB,b@example.com,,VIP\nC,c@example.com,,\n");

        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        foreach ($this->segments as $segment) {
            expect($segment->contacts()->orderBy('email')->pluck('email')->all())->toBe(['a@example.com', 'b@example.com']);
        }
    });

    it('does not look up segments while the rows are processed', function () {
        Queue::fake([ResyncContactSegments::class, SyncSegmentMembership::class]);
        $rows = collect(range(1, 50))->map(fn (int $i): string => "Contact {$i},contact{$i}@example.com,,")->implode("\n");
        $path = importCsv("name,email,phone,tags\n{$rows}\n");

        DB::enableQueryLog();
        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);
        $queries = collect(DB::getQueryLog())->pluck('query');

        $segmentQueries = $queries->filter(fn (string $query): bool => str_contains($query, 'from "segments"'));

        expect(Contact::where('organization_id', $this->org->id)->count())->toBe(50)
            ->and($segmentQueries)->toHaveCount(1)
            ->and($queries->filter(fn (string $query): bool => str_contains(strtolower($query), 'as "segment_')))->toHaveCount(0);
    });
});

describe('contact creation outside imports', function () {
    it('creates an imported contact in the importing organization without queuing a resync', function () {
        publishedOrDraftVipSegment(Tag::factory()->for($this->org)->create());
        Queue::fake([ResyncContactSegments::class, SyncSegmentMembership::class]);

        $result = (new ContactImportService($this->org->id))->processRow(['name' => 'Jane', 'email' => 'jane@example.com'], []);

        $contact = Contact::where('email', 'jane@example.com')->first();

        expect($result['success'])->toBeTrue()
            ->and($contact->organization_id)->toBe($this->org->id);
        Queue::assertNotPushed(ResyncContactSegments::class);
    });

    it('still queues a resync for a contact created through the form', function () {
        publishedOrDraftVipSegment(Tag::factory()->for($this->org)->create());
        Queue::fake([ResyncContactSegments::class, SyncSegmentMembership::class]);

        Livewire::test(CreateContact::class)
            ->fillForm(['name' => 'Jane Doe', 'email' => 'jane@example.com'])
            ->call('create')
            ->assertHasNoFormErrors();

        $contact = Contact::where('email', 'jane@example.com')->first();

        Queue::assertPushed(ResyncContactSegments::class, 1);
        Queue::assertPushed(ResyncContactSegments::class, fn (ResyncContactSegments $job): bool => $job->contactId === $contact->id);
    });
});
