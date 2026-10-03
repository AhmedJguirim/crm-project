<?php

use App\Jobs\ProcessContactImportJob;
use App\Jobs\SyncSegmentMembership;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContactImportFileReader;
use App\Services\ContactImportService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
});

function concurrentImportCsv(string $content): string
{
    $path = 'contact-imports/test-'.uniqid().'.csv';
    Storage::disk('local')->put($path, $content);

    return $path;
}

function holdImportLock(ProcessContactImportJob $job, int $organizationId): Lock
{
    $lock = Cache::lock((new WithoutOverlapping("organization-{$organizationId}"))->getLockKey($job), 1800);
    $lock->get();

    return $lock;
}

describe('one import per organization at a time', function () {
    it('is guarded by a per-organization overlap lock', function () {
        $middleware = (new ProcessContactImportJob('contact-imports/file.csv', 12, 1))->middleware();

        expect($middleware)->toHaveCount(1)
            ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
            ->and($middleware[0]->key)->toBe('organization-12')
            ->and($middleware[0]->releaseAfter)->toBe(30)
            ->and($middleware[0]->expiresAfter)->toBe(1800);
    });

    it('keeps its timeouts consistent', function () {
        $this->freezeTime();
        $job = new ProcessContactImportJob('contact-imports/file.csv', 12, 1);
        $lockExpiry = $job->middleware()[0]->expiresAfter;
        $supervisorTimeout = config('horizon.defaults.supervisor-imports.timeout');

        expect($job->timeout)->toBeLessThan($lockExpiry)
            ->and($lockExpiry)->toBeLessThanOrEqual($supervisorTimeout)
            ->and($supervisorTimeout)->toBeLessThan(config('queue.connections.redis-long.retry_after'))
            ->and($job->failOnTimeout)->toBeTrue()
            ->and($job->maxExceptions)->toBe(1)
            ->and($job->retryUntil()->getTimestamp())->toBe(now()->addHours(3)->getTimestamp());
    });

    it('is failed right away on a timeout or an exception, as the worker sees it', function () {
        config(['queue.default' => 'database', 'queue.long_running_connection' => 'database']);

        ProcessContactImportJob::dispatch('contact-imports/file.csv', $this->org->id, $this->user->id);
        $queued = Queue::connection('database')->pop('imports');

        expect($queued->shouldFailOnTimeout())->toBeTrue()
            ->and($queued->maxExceptions())->toBe(1);
    });

    it('makes a second import of the same organization wait instead of failing', function () {
        config(['queue.default' => 'database', 'queue.long_running_connection' => 'database']);
        $path = concurrentImportCsv("name,email,phone,tags\nJane,jane@example.com,,\n");
        $lock = holdImportLock(new ProcessContactImportJob($path, $this->org->id, $this->user->id), $this->org->id);

        ProcessContactImportJob::dispatch($path, $this->org->id, $this->user->id);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'imports', '--once' => true, '--tries' => 1])->assertSuccessful();

        $waiting = DB::table('jobs')->where('queue', 'imports')->first();

        expect($waiting)->not->toBeNull()
            ->and($waiting->queue)->toBe('imports')
            ->and($waiting->attempts)->toBe(1)
            ->and($waiting->available_at)->toBeGreaterThan(now()->getTimestamp())
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(Contact::where('organization_id', $this->org->id)->count())->toBe(0);

        $lock->release();
        $this->travel(40)->seconds();
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'imports', '--once' => true, '--tries' => 1])->assertSuccessful();

        expect(DB::table('jobs')->where('queue', 'imports')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(Contact::where('organization_id', $this->org->id)->pluck('email')->all())->toBe(['jane@example.com']);
    });

    it('does not block the imports of other organizations', function () {
        $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $otherOrg = $otherUser->personalOrganization();
        holdImportLock(new ProcessContactImportJob('contact-imports/file.csv', $this->org->id, $this->user->id), $this->org->id);
        $path = concurrentImportCsv("name,email,phone,tags\nJohn,john@example.com,,\n");

        ProcessContactImportJob::dispatchSync($path, $otherOrg->id, $otherUser->id);

        expect(Contact::where('organization_id', $otherOrg->id)->pluck('email')->all())->toBe(['john@example.com']);
    });
});

describe('a failed import', function () {
    it('tells the user, deletes the file and syncs the segments of the contacts already created', function () {
        Queue::fake([SyncSegmentMembership::class]);
        $published = Segment::factory()->for($this->org)->published()->count(2)->create();
        Segment::factory()->for($this->org)->create();
        Segment::factory()->for(Organization::factory()->create())->published()->create();
        $path = concurrentImportCsv("name,email,phone,tags\nJane,jane@example.com,,\n");

        (new ProcessContactImportJob($path, $this->org->id, $this->user->id))->failed(new RuntimeException('Boom'));

        $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();

        expect($notification->data['title'])->toBe('Import failed')
            ->and($notification->data['status'])->toBe('danger')
            ->and($notification->data['body'])->toContain('upload the file again')
            ->and(Storage::disk('local')->exists($path))->toBeFalse();
        Queue::assertPushed(SyncSegmentMembership::class, 2);

        foreach ($published as $segment) {
            Queue::assertPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => $job->segmentId === $segment->id);
        }
    });

    it('does not fail when the importing user no longer exists', function () {
        $path = concurrentImportCsv("name,email,phone,tags\nJane,jane@example.com,,\n");

        expect(fn () => (new ProcessContactImportJob($path, $this->org->id, 999999))->failed(null))->not->toThrow(Throwable::class);
        expect(Storage::disk('local')->exists($path))->toBeFalse();
    });

    it('keeps the contacts created before an unexpected exception', function () {
        $path = concurrentImportCsv("name,email,phone,tags\nOne,one@example.com,,\nTwo,two@example.com,,\nThree,three@example.com,,\n");
        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            if (str_starts_with($query->sql, 'insert into "contacts"') && ++$inserts === 2) {
                throw new RuntimeException('Boom');
            }
        });

        expect(fn () => (new ProcessContactImportJob($path, $this->org->id, $this->user->id))->handle(app(ContactImportFileReader::class)))
            ->toThrow(RuntimeException::class, 'Boom');

        expect(Contact::where('organization_id', $this->org->id)->where('email', 'one@example.com')->exists())->toBeTrue()
            ->and(Contact::where('organization_id', $this->org->id)->where('email', 'three@example.com')->exists())->toBeFalse();
    });
});

describe('duplicates that slip through', function () {
    it('reports a contact created after the existence check as a failed row and goes on', function () {
        $path = concurrentImportCsv("name,email,phone,tags\nOne,one@example.com,,\nJane,jane@example.com,,\nThree,three@example.com,,\n");
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced): void {
            if (! $raced && str_contains($query->sql, 'from "contacts"') && in_array('jane@example.com', $query->bindings, true)) {
                $raced = true;
                Contact::factory()->createQuietly(['organization_id' => $this->org->id, 'name' => 'Created elsewhere', 'email' => 'jane@example.com']);
            }
        });

        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();

        expect($raced)->toBeTrue()
            ->and($notification->data['body'])->toContain('Imported: 2 | Failed: 1')
            ->and($notification->data['body'])->toContain("Row 2: A contact with email 'jane@example.com' already exists.")
            ->and(Contact::where('organization_id', $this->org->id)->pluck('email')->sort()->values()->all())->toBe(['jane@example.com', 'one@example.com', 'three@example.com'])
            ->and(Contact::where('email', 'jane@example.com')->value('name'))->toBe('Created elsewhere');
    });

    it('shares a tag that another import created in the meantime', function () {
        $existing = Tag::factory()->for($this->org)->create(['name' => 'Q4-leads']);

        $result = (new ContactImportService($this->org->id))->processRow(['name' => 'Jane', 'email' => 'jane@example.com', 'tags' => 'Q4-leads'], []);

        expect($result['success'])->toBeTrue()
            ->and(Tag::where('organization_id', $this->org->id)->where('name', 'Q4-leads')->count())->toBe(1)
            ->and(Contact::where('email', 'jane@example.com')->first()->tags()->pluck('tags.id')->all())->toBe([$existing->id]);
    });

    it('does not crash when another import creates the tag right after the lookup', function () {
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced): void {
            if (! $raced && str_contains($query->sql, 'from "tags"')) {
                $raced = true;
                DB::table('tags')->insertOrIgnore(['organization_id' => $this->org->id, 'name' => 'Q4-leads', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $result = (new ContactImportService($this->org->id))->processRow(['name' => 'Jane', 'email' => 'jane@example.com', 'tags' => 'Q4-leads'], []);

        expect($result['success'])->toBeTrue()
            ->and(Tag::where('organization_id', $this->org->id)->where('name', 'Q4-leads')->count())->toBe(1)
            ->and(Contact::where('email', 'jane@example.com')->first()->tags()->pluck('name')->all())->toBe(['Q4-leads']);
    });

    it('still restores a trashed tag that is imported again', function () {
        $tag = Tag::factory()->for($this->org)->create(['name' => 'Old']);
        $tag->delete();

        $result = (new ContactImportService($this->org->id))->processRow(['name' => 'Jane', 'email' => 'jane@example.com', 'tags' => 'Old'], []);

        expect($result['success'])->toBeTrue()
            ->and($tag->fresh()->trashed())->toBeFalse()
            ->and(Contact::where('email', 'jane@example.com')->first()->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
    });
});
