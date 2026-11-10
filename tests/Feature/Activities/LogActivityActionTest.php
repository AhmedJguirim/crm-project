<?php

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\OrganizationRole;
use App\Filament\Actions\LogActivityAction;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-11-10 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);
    $this->company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Initech']);
    $this->deal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Retainer',
    ]);
    $this->solo = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => null,
        'company_id' => null,
        'created_by' => $this->user->id,
        'title' => 'Solo',
    ]);

    $this->openDrawer = fn (Deal $deal): Testable => Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $deal->id])
        ->call('mountAction', 'logActivity', [], ['recordKey' => (string) $deal->id]);

    $this->mountedFields = fn (Testable $component): array => collect(
        $component->instance()->getSchema($component->instance()->getMountedActionSchemaName())->getFlatFields(withHidden: true),
    )->all();
});

afterEach(function () {
    Carbon::setTestNow();
});

dataset('log activity pages', [
    'view deal' => ['Retainer', fn () => Livewire::test(ViewDeal::class, ['record' => test()->deal->id])->mountAction('logActivity')],
    'pipeline drawer' => ['Retainer', fn () => (test()->openDrawer)(test()->deal)],
    'edit company' => ['Initech', fn () => Livewire::test(EditCompany::class, ['record' => test()->company->id])->mountAction('logActivity')],
    'view contact' => ['Jane Doe', fn () => Livewire::test(ViewContact::class, ['record' => test()->contact->id])->mountAction('logActivity')],
    'contacts row' => ['Jane Doe', fn () => Livewire::test(ListContacts::class)->mountAction(TestAction::make('logActivity')->table(test()->contact))],
]);

test('the heading names the record, no field is disabled and the date is prefilled', function (string $name, Closure $mount) {
    $component = $mount();

    $component->assertMountedActionModalSee("Log Activity — {$name}")
        ->assertSchemaComponentDoesNotExist('deal')
        ->assertSchemaComponentDoesNotExist('contact_name')
        ->assertActionDataSet(function (array $state): array {
            expect($state['occurred_at'])->not->toBeNull()
                ->and(Carbon::parse($state['occurred_at'])->toDateTimeString())->toBe('2026-11-10 12:00:00')
                ->and($state)->not->toHaveKeys(['deal', 'contact_name', 'contact_id']);

            return [];
        });

    $fields = ($this->mountedFields)($component);

    expect($fields)->not->toBeEmpty();

    foreach ($fields as $field) {
        expect($field->isDisabled())->toBeFalse("{$field->getName()} is disabled");
    }
})->with('log activity pages');

test('the deal modal names the contact', function () {
    Livewire::test(ViewDeal::class, ['record' => $this->deal->id])
        ->mountAction('logActivity')
        ->assertMountedActionModalSee('Contact: Jane Doe');

    Livewire::test(ViewDeal::class, ['record' => $this->solo->id])
        ->mountAction('logActivity')
        ->assertMountedActionModalSee('No contact')
        ->assertMountedActionModalDontSee('Contact: ');
});

dataset('log activity entry points', [
    'deal' => ['deal', true, fn () => Livewire::test(ViewDeal::class, ['record' => test()->deal->id]), fn () => 'logActivity', fn () => test()->deal->id, ['contact' => 'jane', 'deal' => 'retainer', 'company' => null]],
    'solo deal' => ['solo', false, fn () => Livewire::test(ViewDeal::class, ['record' => test()->solo->id]), fn () => 'logActivity', fn () => test()->solo->id, ['contact' => null, 'deal' => 'solo', 'company' => null]],
    'company' => ['company', true, fn () => Livewire::test(EditCompany::class, ['record' => test()->company->id]), fn () => 'logActivity', fn () => null, ['contact' => null, 'deal' => null, 'company' => 'initech']],
    'contact' => ['contact', true, fn () => Livewire::test(ViewContact::class, ['record' => test()->contact->id]), fn () => 'logActivity', fn () => test()->deal->id, ['contact' => 'jane', 'deal' => 'retainer', 'company' => null]],
    'row' => ['row', false, fn () => Livewire::test(ListContacts::class), fn () => TestAction::make('logActivity')->table(test()->contact), fn () => test()->deal->id, ['contact' => 'jane', 'deal' => 'retainer', 'company' => null]],
]);

test('every entry point stores the same fields, notifies and refreshes the feed', function (string $key, bool $fullForm, Closure $page, Closure $action, Closure $dealId, array $expected) {
    $data = [
        'type' => ActivityType::Call,
        'occurred_at' => '2026-11-10 10:00:00',
        'notes' => "Parity {$key}",
        'deal_id' => $dealId(),
    ];

    if ($fullForm) {
        $data += ['subject' => 'S', 'duration_minutes' => 30, 'outcome' => ActivityOutcome::Positive];
    }

    $page()->callAction($action(), data: $data)
        ->assertHasNoActionErrors()
        ->assertNotified('Activity logged')
        ->assertDispatched('activityLogged');

    $activity = Activity::query()->where('notes', "Parity {$key}")->sole();
    $ids = [
        'jane' => $this->contact->id,
        'retainer' => $this->deal->id,
        'solo' => $this->solo->id,
        'initech' => $this->company->id,
        null => null,
    ];

    expect($activity->contact_id)->toBe($ids[$expected['contact']])
        ->and($activity->deal_id)->toBe($ids[$expected['deal']])
        ->and($activity->company_id)->toBe($ids[$expected['company']])
        ->and($activity->user_id)->toBe($this->user->id)
        ->and($activity->type)->toBe(ActivityType::Call)
        ->and($activity->occurred_at->toDateTimeString())->toBe('2026-11-10 10:00:00')
        ->and($activity->subject)->toBe($fullForm ? 'S' : null)
        ->and($activity->duration_minutes)->toBe($fullForm ? 30 : null)
        ->and($activity->outcome)->toBe($fullForm ? ActivityOutcome::Positive : null);
})->with('log activity entry points');

test('the compact row form keeps its fields', function () {
    $component = Livewire::test(ListContacts::class)
        ->assertActionHasLabel(TestAction::make('logActivity')->table($this->contact), 'Log')
        ->mountAction(TestAction::make('logActivity')->table($this->contact));

    $names = array_map(fn ($field): string => $field->getName(), ($this->mountedFields)($component));

    expect($names)->toContain('type', 'occurred_at', 'notes', 'deal_id')
        ->and($names)->not->toContain('duration_minutes')
        ->and($names)->not->toContain('subject')
        ->and($names)->not->toContain('outcome');
});

test('the full form keeps duration, subject and outcome', function () {
    $component = Livewire::test(ViewContact::class, ['record' => $this->contact->id])->mountAction('logActivity');

    $names = array_map(fn ($field): string => $field->getName(), ($this->mountedFields)($component));

    expect($names)->toContain('type', 'occurred_at', 'duration_minutes', 'subject', 'notes', 'outcome', 'deal_id');
});

test('the duration field only shows for the types that have one', function () {
    $visibleNames = fn (Testable $component): array => array_keys($component->instance()
        ->getSchema($component->instance()->getMountedActionSchemaName())
        ->getFlatFields());

    $component = Livewire::test(ViewContact::class, ['record' => $this->contact->id])->mountAction('logActivity');

    expect($visibleNames($component))->not->toContain('duration_minutes');

    $component->fillForm(['type' => ActivityType::Call->value]);
    expect($visibleNames($component))->toContain('duration_minutes');

    $component->fillForm(['type' => ActivityType::Note->value]);
    expect($visibleNames($component))->not->toContain('duration_minutes');
});

test('a hidden duration is not stored', function () {
    Livewire::test(ViewContact::class, ['record' => $this->contact->id])
        ->callAction('logActivity', data: [
            'type' => ActivityType::Note,
            'occurred_at' => '2026-11-10 10:00:00',
            'duration_minutes' => 30,
            'notes' => 'No duration',
        ])
        ->assertHasNoActionErrors();

    expect(Activity::query()->where('notes', 'No duration')->sole()->duration_minutes)->toBeNull();
});

test('a hostile name is escaped in the heading and the description', function () {
    $hostile = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => '<img src=x onerror=alert(1)>']);
    $deal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $hostile->id,
        'created_by' => $this->user->id,
        'title' => '<script>alert(2)</script>',
    ]);

    Livewire::test(ViewContact::class, ['record' => $hostile->id])
        ->mountAction('logActivity')
        ->assertMountedActionModalSeeHtml('Log Activity — &lt;img src=x onerror=alert(1)&gt;')
        ->assertMountedActionModalDontSeeHtml('<img src=x onerror=alert(1)>');

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->mountAction('logActivity')
        ->assertMountedActionModalSeeHtml('Log Activity — &lt;script&gt;alert(2)&lt;/script&gt;')
        ->assertMountedActionModalSeeHtml('Contact: &lt;img src=x onerror=alert(1)&gt;')
        ->assertMountedActionModalDontSeeHtml('<script>alert(2)</script>')
        ->assertMountedActionModalDontSeeHtml('<img src=x onerror=alert(1)>');
});

test('the inline deal create from the contacts row reads deal_notes', function () {
    Livewire::test(ListContacts::class)
        ->callAction(
            [TestAction::make('logActivity')->table($this->contact), TestAction::make('createOption')->schemaComponent('deal_id')],
            data: ['title' => 'Inline row', 'deal_notes' => 'From the row'],
        )
        ->assertHasNoActionErrors();

    $created = Deal::query()->where('title', 'Inline row')->sole();

    expect($created->contact_id)->toBe($this->contact->id)
        ->and($created->notes)->toBe('From the row')
        ->and($created->organization_id)->toBe($this->org->id)
        ->and($created->owner_id)->toBe($this->user->id);
});

test('the deal options of the contact forms are limited to the contact in the record organization', function () {
    $otherOrg = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreign = Deal::factory()->create(['organization_id' => $otherOrg->id, 'title' => 'Foreign']);
    $stranger = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
        'title' => 'Stranger',
    ]);

    foreach ([$foreign, $stranger] as $deal) {
        Livewire::test(ViewContact::class, ['record' => $this->contact->id])
            ->callAction('logActivity', data: ['type' => ActivityType::Call, 'occurred_at' => '2026-11-10 10:00:00', 'deal_id' => $deal->id])
            ->assertHasActionErrors(['deal_id']);

        Livewire::test(ListContacts::class)
            ->callAction(TestAction::make('logActivity')->table($this->contact), data: ['type' => ActivityType::Call, 'occurred_at' => '2026-11-10 10:00:00', 'deal_id' => $deal->id])
            ->assertHasActionErrors(['deal_id']);
    }

    expect(Activity::query()->count())->toBe(0);
});

test('a deal of another organization is refused even when it points at the contact', function () {
    $otherOrg = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreign = Deal::factory()->create(['organization_id' => $otherOrg->id, 'contact_id' => $this->contact->id, 'title' => 'Foreign']);

    Livewire::test(ViewContact::class, ['record' => $this->contact->id])
        ->callAction('logActivity', data: ['type' => ActivityType::Call, 'occurred_at' => '2026-11-10 10:00:00', 'deal_id' => $foreign->id])
        ->assertHasActionErrors(['deal_id']);

    expect(Activity::query()->count())->toBe(0);
});

test('the company form only offers the company deals and the deal link is optional', function () {
    $companyDeal = Deal::factory()->create(['organization_id' => $this->org->id, 'company_id' => $this->company->id, 'title' => 'Initech deal']);

    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->callAction('logActivity', data: ['type' => ActivityType::Call, 'occurred_at' => '2026-11-10 10:00:00', 'deal_id' => $this->solo->id])
        ->assertHasActionErrors(['deal_id']);

    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->callAction('logActivity', data: ['type' => ActivityType::Call, 'occurred_at' => '2026-11-10 10:00:00', 'deal_id' => $companyDeal->id])
        ->assertHasNoActionErrors();

    expect(Activity::query()->sole()->deal_id)->toBe($companyDeal->id);
});

test('the deal form requires a deal while the contact forms do not', function () {
    Livewire::test(ViewDeal::class, ['record' => $this->deal->id])
        ->mountAction('logActivity')
        ->setActionData(['type' => ActivityType::Call, 'occurred_at' => '2026-11-10 10:00:00', 'deal_id' => null])
        ->callMountedAction()
        ->assertHasActionErrors(['deal_id' => 'required']);

    Livewire::test(ViewContact::class, ['record' => $this->contact->id])
        ->callAction('logActivity', data: ['type' => ActivityType::Call, 'occurred_at' => '2026-11-10 10:00:00', 'notes' => 'No deal'])
        ->assertHasNoActionErrors();

    expect(Activity::query()->where('notes', 'No deal')->sole()->deal_id)->toBeNull();
});

describe('the remembered activity type', function () {
    beforeEach(function () {
        $this->typeState = function (Testable $component): ?ActivityType {
            $type = null;

            $component->assertActionDataSet(function (array $state) use (&$type): array {
                $type = $state['type'] instanceof ActivityType ? $state['type'] : ActivityType::tryFrom((string) $state['type']);

                return [];
            });

            return $type;
        };

        $this->logged = fn (ActivityType $type, string $createdAt, array $attributes = []): Activity => Activity::factory()->type($type)->create([
            'organization_id' => $this->org->id,
            'contact_id' => $this->contact->id,
            'user_id' => $this->user->id,
            'created_at' => $createdAt,
            ...$attributes,
        ]);
    });

    test('every entry point prefills my last logged type', function (string $name, Closure $mount) {
        ($this->logged)(ActivityType::Meeting, '2026-11-10 09:00:00');

        $component = $mount();

        expect(($this->typeState)($component))->toBe(ActivityType::Meeting);

        $all = array_map(fn ($field): string => $field->getName(), ($this->mountedFields)($component));
        $visible = array_keys($component->instance()->getSchema($component->instance()->getMountedActionSchemaName())->getFlatFields());

        if (in_array('duration_minutes', $all, true)) {
            expect($visible)->toContain('duration_minutes');
        }
    })->with('log activity pages');

    test('no activity means no default on every entry point', function (string $name, Closure $mount) {
        expect(($this->typeState)($mount()))->toBeNull();
    })->with('log activity pages');

    test('logging through the form changes the next prefill', function () {
        Livewire::test(ViewContact::class, ['record' => $this->contact->id])
            ->callAction('logActivity', data: ['type' => ActivityType::Call, 'occurred_at' => '2026-11-10 10:00:00'])
            ->assertHasNoActionErrors();

        $component = Livewire::test(ViewDeal::class, ['record' => $this->deal->id])->mountAction('logActivity');

        expect(($this->typeState)($component))->toBe(ActivityType::Call);
    });

    test('the latest logged one wins, not the latest occurred one', function () {
        ($this->logged)(ActivityType::Call, '2026-11-10 08:05:00', ['occurred_at' => '2026-11-10 08:00:00']);
        ($this->logged)(ActivityType::Email, '2026-11-10 11:00:00', ['occurred_at' => '2026-11-01 09:00:00']);

        $component = Livewire::test(ViewContact::class, ['record' => $this->contact->id])->mountAction('logActivity');

        expect(($this->typeState)($component))->toBe(ActivityType::Email);
    });

    test('the id breaks a tie on the creation second', function () {
        ($this->logged)(ActivityType::Call, '2026-11-10 09:00:00');
        ($this->logged)(ActivityType::Note, '2026-11-10 09:00:00');

        $component = Livewire::test(ViewContact::class, ['record' => $this->contact->id])->mountAction('logActivity');

        expect(($this->typeState)($component))->toBe(ActivityType::Note);
    });

    test('another user is unaffected and the type stays required', function () {
        $colleague = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($colleague, ['role' => OrganizationRole::Member->value]);
        ($this->logged)(ActivityType::Meeting, '2026-11-10 09:00:00', ['user_id' => $colleague->id]);

        $component = Livewire::test(ViewContact::class, ['record' => $this->contact->id])->mountAction('logActivity');

        expect(($this->typeState)($component))->toBeNull();

        $component->setActionData(['type' => null, 'occurred_at' => '2026-11-10 10:00:00'])
            ->callMountedAction()
            ->assertHasActionErrors(['type' => 'required']);

        expect(Activity::query()->where('user_id', $this->user->id)->count())->toBe(0);
    });

    test('the type is remembered across my organizations and nothing else leaks', function () {
        $globex = Organization::factory()->create(['name' => 'Globex Demo']);
        $globex->members()->attach($this->user, ['role' => OrganizationRole::Member->value]);
        $secret = Contact::factory()->create(['organization_id' => $globex->id, 'name' => 'Secret Globex Person']);

        ($this->logged)(ActivityType::Call, '2026-11-10 09:00:00');
        Activity::factory()->type(ActivityType::WhatsApp)->create([
            'organization_id' => $globex->id,
            'contact_id' => $secret->id,
            'user_id' => $this->user->id,
            'subject' => 'Globex secret subject',
            'created_at' => '2026-11-10 11:30:00',
        ]);

        $component = Livewire::test(ViewContact::class, ['record' => $this->contact->id])->mountAction('logActivity');

        expect(($this->typeState)($component))->toBe(ActivityType::WhatsApp);

        $component->assertMountedActionModalDontSee(['Secret Globex Person', 'Globex secret subject', 'Globex Demo']);
    });

    test('an invalid stored type means no default and no error', function () {
        ($this->logged)(ActivityType::Call, '2026-11-10 09:00:00');

        DB::table('contact_activities')->insert([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'contact_id' => $this->contact->id,
            'type' => 'fax',
            'occurred_at' => '2026-11-10 10:00:00',
            'created_at' => '2026-11-10 11:00:00',
            'updated_at' => '2026-11-10 11:00:00',
        ]);

        $component = Livewire::test(ViewContact::class, ['record' => $this->contact->id])->mountAction('logActivity');

        expect(($this->typeState)($component))->toBeNull();
    });

    test('without a user a userless activity is not remembered', function () {
        ($this->logged)(ActivityType::Meeting, '2026-11-10 09:00:00', ['user_id' => null]);
        auth()->logout();

        $remembered = Closure::bind(fn (): ?ActivityType => self::rememberedType(), null, LogActivityAction::class);

        expect(Activity::query()->whereNull('user_id')->count())->toBe(1)
            ->and($remembered())->toBeNull();
    });

    test('a hostile stored type is not echoed', function () {
        DB::table('contact_activities')->insert([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'contact_id' => $this->contact->id,
            'type' => '<script>alert(1)</script>',
            'occurred_at' => '2026-11-10 10:00:00',
            'created_at' => '2026-11-10 11:00:00',
            'updated_at' => '2026-11-10 11:00:00',
        ]);

        $component = Livewire::test(ViewContact::class, ['record' => $this->contact->id])->mountAction('logActivity');

        expect(($this->typeState)($component))->toBeNull();

        $component->assertMountedActionModalDontSeeHtml('<script>alert(1)</script>');
    });
});
