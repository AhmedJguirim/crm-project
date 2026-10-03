<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\WithTenantContext;
use App\Jobs\ProcessContactImportJob;
use App\Jobs\ResyncContactSegments;
use App\Jobs\SyncSegmentMembership;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDailyDigestNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    $this->acmeUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->globexUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->acme = $this->acmeUser->personalOrganization();
    $this->globex = $this->globexUser->personalOrganization();

    $leadsRule = fn (): SegmentRuleData => new SegmentRuleData('rule-1', 'Leads', [
        SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Lead->value]),
    ]);

    foreach ([$this->acme, $this->globex] as $organization) {
        Contact::factory()->for($organization)->count(2)->create(['status' => ContactStatus::Lead]);
        Segment::factory()->for($organization)->published()->withRules([$leadsRule()])->create();
    }

    $this->memberIds = fn (Segment $segment): array => DB::table('contact_segment')->where('segment_id', $segment->id)->pluck('contact_id')->sort()->values()->all();
});

describe('the scope', function () {
    it('returns no rows without a tenant or a context', function () {
        expect(Contact::count())->toBe(0)
            ->and(Segment::query()->get())->toBeEmpty()
            ->and(Tag::count())->toBe(0);
    });

    it('still sees everything when a query opts out explicitly', function () {
        expect(Contact::withoutGlobalScope('organization')->count())->toBe(4)
            ->and(Contact::forOrganization($this->acme->id)->count())->toBe(2)
            ->and(Contact::forOrganization($this->acme->id)->pluck('organization_id')->unique()->all())->toBe([$this->acme->id]);
    });

    it('filters by the context', function () {
        $organizationIds = app(TenantContext::class)->run($this->acme->id, fn () => Contact::pluck('organization_id')->unique()->all());

        expect($organizationIds)->toBe([$this->acme->id]);
    });

    it('prefers the panel tenant over the context', function () {
        $this->actingAs($this->globexUser);
        Filament::setTenant($this->globex);

        $organizationIds = app(TenantContext::class)->run($this->acme->id, fn () => Contact::pluck('organization_id')->unique()->all());

        expect($organizationIds)->toBe([$this->globex->id]);
    });

    it('restores the previous context after nested runs and exceptions', function () {
        $context = app(TenantContext::class);
        $seen = [];

        $context->run($this->acme->id, function () use ($context, &$seen): void {
            $context->run($this->globex->id, function () use ($context, &$seen): void {
                $seen['inner'] = $context->id();
            });

            $seen['after inner'] = $context->id();
        });

        expect($seen)->toBe(['inner' => $this->globex->id, 'after inner' => $this->acme->id]);

        expect(fn () => $context->run($this->acme->id, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class)
            ->and($context->id())->toBeNull();
    });

    it('returns what the callback returns', function () {
        expect(app(TenantContext::class)->run($this->acme->id, fn (): string => 'done'))->toBe('done');
    });

    it('gives new records the organization of the context, without overriding an explicit one', function () {
        $context = app(TenantContext::class);

        $created = $context->run($this->acme->id, fn (): Tag => Tag::create(['name' => 'Inferred']));
        $explicit = $context->run($this->acme->id, fn (): Tag => Tag::create(['name' => 'Explicit', 'organization_id' => $this->globex->id]));

        expect($created->organization_id)->toBe($this->acme->id)
            ->and($explicit->organization_id)->toBe($this->globex->id);
    });

    it('is a scoped binding, so a worker starts every job with a fresh context', function () {
        app(TenantContext::class)->set($this->acme->id);

        expect(app(TenantContext::class)->id())->toBe($this->acme->id);

        app()->forgetScopedInstances();

        expect(app(TenantContext::class)->id())->toBeNull();
    });
});

describe('jobs', function () {
    beforeEach(function () {
        Storage::fake('local');

        $this->taggedSegment = function (Organization $organization): array {
            $tag = Tag::factory()->for($organization)->create();
            $tagged = Contact::forOrganization($organization->id)->first();
            $tagged->tags()->attach($tag);
            $segment = Segment::factory()->for($organization)->published()->withRules([
                new SegmentRuleData('rule-1', 'Tagged', [
                    SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]),
                ]),
            ])->create();

            return [$segment, $tagged];
        };
    });

    it('imports contacts with tags without a panel tenant, and the segment of the organization picks them up', function () {
        $tag = Tag::factory()->for($this->acme)->create(['name' => 'VIP']);
        $segment = Segment::factory()->for($this->acme)->published()->withRules([
            new SegmentRuleData('rule-1', 'VIPs', [
                SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]),
            ]),
        ])->create();
        Storage::disk('local')->put('contact-imports/tags.csv', "name,email,phone,tags\nAnna,anna@example.com,,VIP\nBen,ben@example.com,,Fresh\n");

        ProcessContactImportJob::dispatchSync('contact-imports/tags.csv', $this->acme->id, $this->acmeUser->id);

        $anna = Contact::forOrganization($this->acme->id)->where('email', 'anna@example.com')->sole();

        expect(Tag::forOrganization($this->acme->id)->pluck('name')->sort()->values()->all())->toBe(['Fresh', 'VIP'])
            ->and(Tag::forOrganization($this->globex->id)->count())->toBe(0)
            ->and(($this->memberIds)($segment))->toBe([$anna->id]);
    });

    it('resyncs a contact in the organization of the contact', function () {
        [$segment, $tagged] = ($this->taggedSegment)($this->acme);

        ResyncContactSegments::dispatchSync($tagged->id);

        expect(($this->memberIds)($segment))->toBe([$tagged->id]);
    });

    it('does nothing for a contact that no longer exists', function () {
        ResyncContactSegments::dispatchSync(999_999);

        expect(DB::table('contact_segment')->count())->toBe(0);
    });

    it('syncs a segment with the contacts of its own organization only', function () {
        [$acmeSegment, $acmeTagged] = ($this->taggedSegment)($this->acme);
        [$globexSegment, $globexTagged] = ($this->taggedSegment)($this->globex);

        SyncSegmentMembership::dispatchSync($globexSegment->id);

        expect(($this->memberIds)($globexSegment))->toBe([$globexTagged->id])
            ->and(($this->memberIds)($acmeSegment))->toBe([]);
    });

    it('does not leak the context out of a job', function () {
        $job = new class
        {
            public function handle(): void {}
        };

        (new WithTenantContext($this->acme->id))->handle($job, function (): void {
            expect(Contact::count())->toBe(2);
        });

        expect(Contact::count())->toBe(0)
            ->and(app(TenantContext::class)->id())->toBeNull();
    });

    it('runs without a context when the record of the job is gone', function () {
        $seen = 'unset';

        (new WithTenantContext(fn (): ?int => null))->handle(new stdClass, function () use (&$seen): void {
            $seen = app(TenantContext::class)->id();
        });

        expect($seen)->toBeNull();
    });
});

describe('commands and notifications', function () {
    beforeEach(function () {
        Notification::fake();
        config(['tasks.notifications.daily_digest_enabled' => true]);

        $this->acmeTask = Task::factory()->create(['organization_id' => $this->acme->id, 'created_by' => $this->acmeUser->id, 'status' => TaskStatus::Pending, 'due_at' => now()->subDay()]);
        $this->globexTask = Task::factory()->create(['organization_id' => $this->globex->id, 'created_by' => $this->globexUser->id, 'status' => TaskStatus::Pending, 'due_at' => now()->addDay()]);
    });

    it('sends each organization the digest of its own tasks', function () {
        Artisan::call('tasks:send-digest', ['--all' => true]);

        Notification::assertSentTo($this->acmeUser, TaskDailyDigestNotification::class, fn (TaskDailyDigestNotification $notification): bool => $notification->overdueTasks->modelKeys() === [$this->acmeTask->id]
            && $notification->dueTodayTasks->isEmpty());
        Notification::assertNotSentTo($this->globexUser, TaskDailyDigestNotification::class);
    });

    it('sends each organization the reminders of its own tasks', function () {
        Artisan::call('tasks:send-reminders', ['--all' => true]);

        Notification::assertSentTo($this->globexUser, TaskDueSoonNotification::class, fn (TaskDueSoonNotification $notification): bool => $notification->task->is($this->globexTask));
        Notification::assertNotSentTo($this->acmeUser, TaskDueSoonNotification::class);
    });

    it('runs queued task notifications in the context of their organization', function () {
        $notification = new TaskDueSoonNotification($this->acmeTask, $this->acme, 'key');
        $middleware = $notification->middleware($this->acmeUser, 'database');

        expect($middleware)->toHaveCount(1)
            ->and($middleware[0])->toBeInstanceOf(WithTenantContext::class);

        $seen = null;
        $middleware[0]->handle(new stdClass, function () use (&$seen): void {
            $seen = app(TenantContext::class)->id();
        });

        expect($seen)->toBe($this->acme->id);
    });
});

describe('http', function () {
    it('renders the invoice pdf with its contact and deal without a panel tenant', function () {
        $contact = Contact::forOrganization($this->acme->id)->first();
        $deal = Deal::factory()->create(['organization_id' => $this->acme->id, 'contact_id' => $contact->id, 'created_by' => $this->acmeUser->id]);
        $invoice = Invoice::factory()->create(['organization_id' => $this->acme->id, 'contact_id' => $contact->id, 'deal_id' => $deal->id, 'status' => InvoiceStatus::Sent]);
        $rendered = null;
        View::composer('pdf.invoice', function ($view) use (&$rendered): void {
            $rendered = $view->getData()['invoice'];
        });

        $this->actingAs($this->acmeUser)->get(route('invoices.pdf', $invoice))->assertOk();

        expect($rendered->contact->name)->toBe($contact->name)
            ->and($rendered->deal->title)->toBe($deal->title);

        $foreign = Invoice::factory()->create(['organization_id' => $this->globex->id, 'contact_id' => Contact::forOrganization($this->globex->id)->first()->id, 'status' => InvoiceStatus::Sent]);

        $this->actingAs($this->acmeUser)->get(route('invoices.pdf', $foreign))->assertForbidden();
    });

    it('opens the tag page of the organization of the user only', function () {
        $acmeTag = Tag::factory()->for($this->acme)->create();
        $globexTag = Tag::factory()->for($this->globex)->create();

        $this->actingAs($this->acmeUser)->get(route('app.tags.show', $acmeTag))->assertOk();
        $this->actingAs($this->acmeUser)->get(route('app.tags.show', $globexTag))->assertNotFound();
    });

    it('sets the context from the organization in the url', function () {
        Tag::factory()->for($this->acme)->create(['name' => 'Acme tag']);
        Tag::factory()->for($this->globex)->create(['name' => 'Globex tag']);

        $this->actingAs($this->acmeUser)->get(route('app.tags.index', $this->acme))
            ->assertOk()
            ->assertSee('Acme tag')
            ->assertDontSee('Globex tag');
    });
});
