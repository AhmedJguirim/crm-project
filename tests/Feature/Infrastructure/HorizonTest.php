<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Pages\Auth\Register;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Jobs\ProcessContactImportJob;
use App\Jobs\ResyncContactSegments;
use App\Jobs\SyncSegmentMembership;
use App\Models\Segment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function horizonSupervisors(): array
{
    return config('horizon.defaults');
}

describe('queue routing', function () {
    beforeEach(function () {
        $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $this->org = $this->user->personalOrganization();
        $this->actingAs($this->user);
        Filament::setTenant($this->org);
        Queue::fake();
    });

    it('sends each job to its own queue', function (Closure $dispatch, string $queue) {
        $dispatch();

        Queue::assertPushedOn($queue, $queue === 'segments' ? SyncSegmentMembership::class : ProcessContactImportJob::class);
    })->with([
        'segment sync' => [fn () => SyncSegmentMembership::dispatch(1), 'segments'],
        'contact import' => [fn () => ProcessContactImportJob::dispatch('contact-imports/file.csv', 1, 1), 'imports'],
    ]);

    it('leaves the quick jobs on the default queue', function () {
        ResyncContactSegments::dispatch(1);

        expect(Queue::pushed(ResyncContactSegments::class)->first()->queue)->toBeNull()
            ->and(config('queue.connections.redis.queue'))->toBe('default');
    });

    it('queues the sync of a published segment on the segments queue', function () {
        $segment = Segment::factory()->for($this->org)->withRules([new SegmentRuleData('rule-1', 'Leads', [
            SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Lead->value]),
        ])])->create();

        Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()])->callAction('publish');

        Queue::assertPushedOn('segments', SyncSegmentMembership::class);
    });

    it('queues an uploaded contacts file on the imports queue', function () {
        Storage::fake('local');

        Livewire::test(ListContacts::class)
            ->callAction('importContacts', ['file' => UploadedFile::fake()->create('contacts.csv', 10, 'text/csv')])
            ->assertNotified('Import queued');

        Queue::assertPushedOn('imports', ProcessContactImportJob::class);
    });
});

describe('horizon configuration', function () {
    it('keeps redis from handing a running job to a second worker', function () {
        $longestTimeout = collect(horizonSupervisors())->max('timeout');

        expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan($longestTimeout);
    });

    it('lets the segments supervisor outlive a segment sync', function () {
        $job = new SyncSegmentMembership(1);
        $lockExpiry = $job->middleware()[0]->expiresAfter;

        expect($job->timeout)->toBe(600)
            ->and($lockExpiry)->toBeGreaterThan($job->timeout)
            ->and(horizonSupervisors()['supervisor-segments']['timeout'])->toBeGreaterThanOrEqual($lockExpiry);
    });

    it('lets the imports supervisor outlive an import', function () {
        $job = new ProcessContactImportJob('contact-imports/file.csv', 1, 1);

        expect(horizonSupervisors()['supervisor-imports']['timeout'])->toBeGreaterThan($job->timeout);
    });

    it('has a supervisor for every queue the jobs use', function () {
        $listened = collect(horizonSupervisors())->pluck('queue')->flatten()->unique()->all();
        $used = [(new SyncSegmentMembership(1))->queue, (new ProcessContactImportJob('f.csv', 1, 1))->queue, config('queue.connections.redis.queue')];

        expect($listened)->toEqualCanonicalizing(['default', 'segments', 'imports'])
            ->and($used)->each->toBeIn($listened);
    });

    it('runs every supervisor in each environment', function (string $environment) {
        expect(array_keys(config("horizon.environments.{$environment}")))->toEqualCanonicalizing(array_keys(horizonSupervisors()));
    })->with(['local', 'production']);

    it('schedules the horizon snapshots every five minutes', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'horizon:snapshot'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('*/5 * * * *');
    });
});

describe('dashboard access', function () {
    beforeEach(function () {
        app()->detectEnvironment(fn (): string => 'production');
        $this->ops = User::factory()->create(['email' => 'ops@example.com']);
        config(['horizon.allowed_user_ids' => [$this->ops->id]]);
    });

    it('lets an allowed user open horizon', function () {
        $this->actingAs($this->ops)->get('/horizon')->assertSuccessful();
    });

    it('keeps access for an allowed user who changes their email', function () {
        $this->actingAs($this->ops);

        Livewire::test('pages::settings.profile')
            ->set('name', $this->ops->name)
            ->set('email', 'ops.new@example.com')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->get('/horizon')->assertSuccessful();
    });

    it('refuses an organization owner who is not allowed', function () {
        $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'owner@example.com']);

        $this->actingAs($owner)->get('/horizon')->assertForbidden();
    });

    it('refuses guests', function () {
        $this->get('/horizon')->assertForbidden();
    });

    it('refuses someone who registers with the email of an allowed person', function () {
        config(['horizon.allowed_user_ids' => [987654], 'horizon.allowed_emails' => ['ops@ourcompany.example']]);
        app()->detectEnvironment(fn (): string => 'testing');

        Livewire::test(Register::class)
            ->fillForm([
                'name' => 'Impostor',
                'email' => 'ops@ourcompany.example',
                'password' => 'password',
                'passwordConfirmation' => 'password',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertAuthenticated();
        // the register form can't be filled in tests while the environment is production
        app()->detectEnvironment(fn (): string => 'production');
        $this->get('/horizon')->assertForbidden();
    });

    it('refuses someone who changes their own email to the one of an allowed person', function () {
        config(['horizon.allowed_user_ids' => [987654], 'horizon.allowed_emails' => ['ops@ourcompany.example']]);
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.profile')
            ->set('name', 'Impostor')
            ->set('email', 'ops@ourcompany.example')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->get('/horizon')->assertForbidden();
    });

    it('ignores an email list', function () {
        config(['horizon.allowed_emails' => ['owner@example.com']]);
        $owner = User::factory()->create(['email' => 'owner@example.com']);

        $this->actingAs($owner)->get('/horizon')->assertForbidden();
    });

    it('is open locally', function () {
        app()->detectEnvironment(fn (): string => 'local');

        $this->get('/horizon')->assertSuccessful();
    });

    it('reads the allowed user ids from a comma separated list', function () {
        $result = Process::path(base_path())
            ->env(['HORIZON_ALLOWED_USER_IDS' => ' 1, 7 ,abc,,-3,2x'])
            ->run(['php', 'artisan', 'tinker', '--execute=echo json_encode(config("horizon.allowed_user_ids"));']);

        expect($result->output())->toContain('[1,7]');
    });
});

describe('local development', function () {
    it('runs horizon instead of queue:listen', function () {
        $dev = implode(' ', json_decode(file_get_contents(base_path('composer.json')), true)['scripts']['dev']);

        expect($dev)->toContain('php artisan horizon')->not->toContain('queue:listen');
    });
});
