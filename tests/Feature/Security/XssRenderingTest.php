<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\RelationManagers\DealsRelationManager;
use App\Filament\Resources\CompanyTypes\Pages\ManageCompanyTypes;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Filament\Resources\Contacts\Widgets\ContactDetailsWidget;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\Widgets\DealActivityFeed;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Filament\Resources\Deals\Widgets\DealStageHistoryWidget;
use App\Filament\Resources\LostReasons\Pages\ManageLostReasons;
use App\Filament\Resources\PipelineStages\Pages\ManagePipelineStages;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Segments\Pages\SegmentRuleEngine;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Widgets\DealsOverviewWidget;
use App\Filament\Widgets\RecentActivitiesWidget;
use App\Models\Activity;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\LostReason;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use App\Services\Deals\DefaultPipeline;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch;
use Livewire\Livewire;
use Tests\Feature\Security\SecurityPayloads;

dataset('xssListPages', [
    'ListContacts' => [ListContacts::class],
    'ListCompanies' => [ListCompanies::class],
    'ListDeals' => [ListDeals::class],
    'ListTasks' => [ListTasks::class],
    'ListSegments' => [ListSegments::class],
    'ListTags' => [ListTags::class],
    'ListCustomFields' => [ListCustomFields::class],
    'ManageCompanyTypes' => [ManageCompanyTypes::class],
    'ManageLostReasons' => [ManageLostReasons::class],
]);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Test Owner']);
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->contactNamed = fn (string $name): Contact => Contact::factory()->create(['organization_id' => $this->org->id, 'name' => $name]);
    $this->companyNamed = fn (string $name, array $attributes = []): Company => Company::factory()->create(['organization_id' => $this->org->id, 'name' => $name, ...$attributes]);
    $this->dealFor = fn (string $title, Contact $contact, ?Company $company = null): Deal => Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'company_id' => $company?->id,
        'created_by' => $this->owner->id,
        'title' => $title,
    ]);
});

describe('list pages', function () {
    test('a name typed as markup is shown as text', function (string $page, string $payload) {
        $type = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => $payload]);
        LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => $payload]);
        $company = ($this->companyNamed)($payload, ['company_type_id' => $type->id]);
        $contact = ($this->contactNamed)($payload);
        $tag = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => $payload]);
        $contact->tags()->attach($tag->id);
        $contact->companies()->attach($company->id);
        ($this->dealFor)($payload, $contact, $company);
        Task::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'created_by' => $this->owner->id, 'title' => $payload]);
        Segment::factory()->create(['organization_id' => $this->org->id, 'name' => $payload]);
        CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => $payload, 'type' => 'text', 'unique' => false, 'order' => 1]);

        $html = Livewire::test($page)->assertSuccessful()->html();

        $visible = $page === ListCustomFields::class ? mb_substr($payload, 0, 50) : $payload;

        SecurityPayloads::assertHtmlIsInert($html, $visible);
    })->with('xssListPages')->with(SecurityPayloads::xss());
});

describe('record pages', function () {
    test('a contact page shows custom field names, values and option labels as text', function (string $payload) {
        $text = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => $payload, 'type' => 'text', 'unique' => false, 'order' => 1]);
        $tier = CustomField::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'Tier',
            'type' => 'select',
            'unique' => false,
            'order' => 2,
            'options' => [['label' => $payload, 'value' => 'gold']],
        ]);
        $contact = ($this->contactNamed)($payload);
        $contact->update(['custom_field_values' => [$text->key => $payload, $tier->key => 'gold']]);
        $company = ($this->companyNamed)($payload);
        $contact->companies()->attach($company->id);
        $contact->tags()->attach(Tag::factory()->create(['organization_id' => $this->org->id, 'name' => $payload])->id);

        SecurityPayloads::assertHtmlIsInert(Livewire::test(ViewContact::class, ['record' => $contact->id])->assertSuccessful()->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(ContactDetailsWidget::class, ['record' => $contact])->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(EditContact::class, ['record' => $contact->id])->assertSuccessful()->html(), $payload);
    })->with(SecurityPayloads::xss());

    test('a deal page shows its title, contact and company as text', function (string $payload) {
        $contact = ($this->contactNamed)($payload);
        $company = ($this->companyNamed)($payload);
        $deal = ($this->dealFor)($payload, $contact, $company);

        SecurityPayloads::assertHtmlIsInert(Livewire::test(ViewDeal::class, ['record' => $deal->id])->assertSuccessful()->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(DealDetailsWidget::class, ['record' => $deal])->html(), $payload);
    })->with(SecurityPayloads::xss());

    test('a pipeline stage named as markup is shown as text in the deal list, tab, page and overview', function (string $payload) {
        $company = ($this->companyNamed)('Acme Ltd');
        $deal = ($this->dealFor)('Retainer', ($this->contactNamed)('Ann'), $company);
        $stage = pipelineStageNamed($this->org->id, 'Lead');
        $deal->update(['pipeline_stage_id' => $stage->id]);
        $stage->update(['name' => $payload]);

        SecurityPayloads::assertHtmlIsInert(Livewire::test(ListDeals::class)->assertSuccessful()->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(DealsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class])->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(DealDetailsWidget::class, ['record' => $deal])->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(DealStageHistoryWidget::class, ['record' => $deal])->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(DealsOverviewWidget::class)->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(ManagePipelineStages::class)->assertSuccessful()->html(), $payload);
    })->with(SecurityPayloads::xss());

    test('a user named as markup is shown as text in the stage history of a deal', function (string $payload) {
        $mover = User::factory()->create(['name' => $payload]);
        $this->org->members()->attach($mover, ['role' => OrganizationRole::Member->value]);
        $deal = ($this->dealFor)('Retainer', ($this->contactNamed)('Ann'));
        $this->actingAs($mover);
        $origin = $deal->pipelineStage;
        DealStageMover::move($deal, pipelineStageNamed($this->org->id, 'Discovery'));
        $origin->update(['name' => $payload]);

        $html = Livewire::test(DealStageHistoryWidget::class, ['record' => $deal])->assertSee('Discovery')->html();

        SecurityPayloads::assertHtmlIsInert($html, $payload);
    })->with(SecurityPayloads::xss());

    test('a pipeline stage named as markup is shown as text in the delete modal, its heading and its target select', function (string $payload) {
        $company = ($this->companyNamed)('Acme Ltd');
        ($this->dealFor)('Retainer', ($this->contactNamed)('Ann'), $company)->update(['pipeline_stage_id' => pipelineStageNamed($this->org->id, 'Discovery')->id]);
        pipelineStageNamed($this->org->id, 'Lead')->update(['name' => $payload]);
        $delete = fn (string $name) => TestAction::make(DeleteAction::class)->table(pipelineStageNamed($this->org->id, $name));

        SecurityPayloads::assertHtmlIsInert(Livewire::test(ManagePipelineStages::class)->mountAction($delete('Discovery'))->assertSuccessful()->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(ManagePipelineStages::class)->mountAction($delete($payload))->assertSuccessful()->html(), $payload);
    })->with(SecurityPayloads::xss());

    test('a segment page and its rule engine show names and values as text', function (string $payload) {
        $tag = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => $payload]);
        $company = ($this->companyNamed)($payload);
        $wonStage = DefaultPipeline::stageOfType($this->org->id, DealStatus::Won);
        $wonStage->update(['name' => $payload]);
        $rule = new SegmentRuleData('rule-1', $payload, [
            new SegmentConditionData('condition-1', SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Is, ['value' => $payload]),
            new SegmentConditionData('condition-2', SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]),
            new SegmentConditionData('condition-3', SegmentConditionType::Company, null, SegmentOperator::BelongsToAnyOf, ['values' => [$company->id]]),
            new SegmentConditionData('condition-4', SegmentConditionType::Deal, null, SegmentOperator::HasDeal, ['deal_stages' => [$wonStage->id]]),
        ]);
        $segment = Segment::factory()->published()->withRules([$rule])->create(['organization_id' => $this->org->id, 'name' => $payload]);
        $segment->update(['draft_rules' => $segment->rules]);

        SecurityPayloads::assertHtmlIsInert(Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()])->assertSuccessful()->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(SegmentRuleEngine::class, ['record' => $segment->getRouteKey()])->assertSuccessful()->html(), $payload);
    })->with(SecurityPayloads::xss());
});

describe('activity feeds', function () {
    test('a subject, notes and author typed as markup are shown as text', function (string $payload) {
        $author = User::factory()->create(['name' => $payload]);
        $contact = ($this->contactNamed)('Ann Lee');
        $deal = ($this->dealFor)('Renewal', $contact);
        Activity::factory()->create([
            'organization_id' => $this->org->id,
            'contact_id' => $contact->id,
            'deal_id' => $deal->id,
            'user_id' => $author->id,
            'subject' => $payload,
            'notes' => $payload,
            'occurred_at' => now()->subHour(),
        ]);

        SecurityPayloads::assertHtmlIsInert(Livewire::test(ContactActivityFeed::class, ['record' => $contact])->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(DealActivityFeed::class, ['record' => $deal])->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(RecentActivitiesWidget::class)->html(), $payload);
    })->with(SecurityPayloads::xss());
});

describe('global search', function () {
    test('results named like a script are shown as text', function () {
        $payload = '<script>alert(1)</script>';
        $contact = ($this->contactNamed)($payload);
        $company = ($this->companyNamed)($payload);
        ($this->dealFor)($payload, $contact, $company);
        Task::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'created_by' => $this->owner->id, 'title' => $payload]);

        SecurityPayloads::assertHtmlIsInert(Livewire::test(GlobalSearch::class)->set('search', 'alert')->html(), $payload);
    });

    test('a result named like a styled link is shown as text', function () {
        $payload = '<a href="https://evil.example" style="position:fixed;inset:0">x</a>';
        ($this->contactNamed)($payload);

        SecurityPayloads::assertHtmlIsInert(Livewire::test(GlobalSearch::class)->set('search', 'evil')->html(), $payload);
    });
});

describe('the rule engine', function () {
    test('a forged rule id can not leave the JavaScript string', function () {
        $forged = "x');alert(5);('";
        $segment = Segment::factory()->for($this->org)->withRules([new SegmentRuleData($forged, 'VIP', [])])->create(['name' => 'Forged rules']);

        $page = Livewire::test(SegmentRuleEngine::class, ['record' => $segment->getRouteKey()]);

        expect($page->html())
            ->toContain("selectRule('x\\u0027);alert(5);(\\u0027')")
            ->not->toContain("selectRule('x&#039;);alert(5)")
            ->not->toContain("selectRule('x');alert(5)");

        $page->call('selectRule', $forged)->assertSet('selectedRuleId', $forged);
    });
});
