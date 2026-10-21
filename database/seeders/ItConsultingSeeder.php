<?php

namespace Database\Seeders;

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\CompanyIndustry;
use App\Enums\ContactAttribute;
use App\Enums\DealStatus;
use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\SyncSegmentMembership;
use App\Models\Activity;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\LostReason;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Deals\DealCompany;
use App\Services\Deals\DealStageMover;
use App\Services\Deals\DefaultPipeline;
use App\Services\Invoices\InvoiceLedger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class ItConsultingSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'onboarding_completed' => true,
            ]
        );

        if (! $user->onboarding_completed) {
            $user->update(['onboarding_completed' => true]);
        }

        $org = $user->personalOrganization() ?? $user->createPersonalOrganization();

        $this->seedRoleUsers($org);

        app(TenantContext::class)->run($org->getKey(), fn () => $this->seedOrganization($user, $org));

        $this->seedSecondOrganization($user);
    }

    /**
     * One user per role in the demo organization, to try what each role can do (all with the password of the test
     * user).
     */
    private function seedRoleUsers(Organization $organization): void
    {
        foreach ([
            'admin@example.com' => ['Demo Admin', OrganizationRole::Admin],
            'member@example.com' => ['Demo Member', OrganizationRole::Member],
            'viewer@example.com' => ['Demo Viewer', OrganizationRole::Viewer],
        ] as $email => [$name, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'onboarding_completed' => true,
                ]
            );

            $organization->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
        }
    }

    /**
     * A second organization for manual cross-organization checks. It's owned by another user (`other@example.com`), and
     * the test user is a plain member, so the tenant switcher can be tried without logging out. Its data looks like the
     * first organization's (a "VIP" tag and segment), so anything leaking across organizations is easy to spot.
     */
    private function seedSecondOrganization(User $testUser): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'other@example.com'],
            [
                'name' => 'Other User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'onboarding_completed' => true,
            ]
        );

        $organization = Organization::firstOrCreate(
            ['slug' => 'globex-demo'],
            ['name' => 'Globex Demo', 'personal_team' => false, 'created_by' => $owner->id],
        );

        $organization->members()->syncWithoutDetaching([
            $owner->id => ['role' => OrganizationRole::Owner->value],
            $testUser->id => ['role' => OrganizationRole::Member->value],
        ]);

        app(TenantContext::class)->run($organization->getKey(), function () use ($organization): void {
            $vip = Tag::firstOrCreate(['organization_id' => $organization->id, 'name' => 'VIP'], ['color' => '#8b5cf6']);

            $contacts = [
                ['name' => 'Globex Alice', 'email' => 'alice@globex.test', 'tags' => [$vip->id]],
                ['name' => 'Globex Bob', 'email' => 'bob@globex.test', 'tags' => [$vip->id]],
                ['name' => 'Globex Carol', 'email' => 'carol@globex.test', 'tags' => []],
            ];

            foreach ($contacts as $data) {
                Contact::firstOrCreate(
                    ['organization_id' => $organization->id, 'email' => $data['email']],
                    ['name' => $data['name']],
                )->tags()->sync($data['tags']);
            }

            $segment = Segment::firstOrCreate(
                ['organization_id' => $organization->id, 'name' => 'VIP'],
                [
                    'rules' => [(new SegmentRuleData((string) Str::ulid(), 'VIP tag', [
                        SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$vip->id]]),
                    ]))->toArray()],
                    'is_published' => true,
                ],
            );

            SyncSegmentMembership::dispatchSync($segment->id);
        });
    }

    /**
     * Seeds the organization's data inside its tenant context, so every tenant model query is scoped to it.
     */
    private function seedOrganization(User $user, Organization $org): void
    {
        // ── Tags ──────────────────────────────────────────────────────────────
        $tagsData = [
            ['name' => 'Client',     'color' => '#22c55e'],
            ['name' => 'Prospect',   'color' => '#3b82f6'],
            ['name' => 'Lead',       'color' => '#f59e0b'],
            ['name' => 'VIP',        'color' => '#8b5cf6'],
            ['name' => 'Cold',       'color' => '#94a3b8'],
            ['name' => 'Technical',  'color' => '#0ea5e9'],
            ['name' => 'Management', 'color' => '#ec4899'],
            ['name' => 'DevOps',     'color' => '#f97316'],
            ['name' => 'Security',   'color' => '#ef4444'],
            ['name' => 'Partner',    'color' => '#14b8a6'],
        ];

        $tags = [];
        foreach ($tagsData as $data) {
            $tags[$data['name']] = Tag::firstOrCreate(
                ['organization_id' => $org->id, 'name' => $data['name']],
                ['color' => $data['color']]
            );
        }

        // ── Custom Fields ─────────────────────────────────────────────────────
        $jobTitle = $this->field($org->id, 'Job Title', 'text', null, 1);
        $linkedin = $this->field($org->id, 'LinkedIn', 'url', null, 2);
        $contractType = $this->field($org->id, 'Contract Type', 'select', [
            ['label' => 'Retainer'],
            ['label' => 'Project-Based'],
            ['label' => 'Time & Materials'],
            ['label' => 'Fixed Price'],
        ], 3);
        $techStack = $this->field($org->id, 'Tech Stack', 'multiselect', [
            ['label' => 'PHP / Laravel'],
            ['label' => 'JavaScript / Node.js'],
            ['label' => 'React'],
            ['label' => 'Vue.js'],
            ['label' => 'Python'],
            ['label' => 'AWS'],
            ['label' => 'Azure'],
            ['label' => 'Docker / Kubernetes'],
            ['label' => 'PostgreSQL'],
        ], 4);
        $hourlyRate = $this->field($org->id, 'Hourly Rate (€)', 'number', null, 5);
        $contractStart = $this->field($org->id, 'Contract Start', 'date', null, 6);
        $contractEnd = $this->field($org->id, 'Contract End', 'date', null, 7);
        $ndaStatus = $this->field($org->id, 'NDA Status', 'select', [
            ['label' => 'Signed'],
            ['label' => 'Pending'],
            ['label' => 'Not Required'],
        ], 8);
        $notes = $this->field($org->id, 'Internal Notes', 'textarea', null, 9);

        // ── Contacts ──────────────────────────────────────────────────────────
        $contactsData = [
            [
                'name' => 'Marcus Chen',
                'email' => 'marcus.chen@techcorp.io',
                'phone' => '+33 6 12 34 56 78',
                'tags' => ['Client', 'VIP', 'Technical'],
                'companies' => ['TechCorp Solutions'],
                'cf' => [
                    $jobTitle->key => 'CTO',
                    $linkedin->key => 'https://linkedin.com/in/marcuschen',
                    $contractType->key => $this->optionValue($contractType, 'Retainer'),
                    $techStack->key => $this->optionValues($techStack, ['PHP / Laravel', 'AWS', 'Docker / Kubernetes']),
                    $hourlyRate->key => 150,
                    $contractStart->key => '2025-01-15',
                    $contractEnd->key => '2025-12-31',
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Signed'),
                    $notes->key => 'Key account. Prefers morning calls. Annual review in December.',
                ],
            ],
            [
                'name' => 'Sarah Johansson',
                'email' => 'sarah.johansson@cloudbase.dev',
                'phone' => '+46 70 234 5678',
                'tags' => ['Client', 'DevOps'],
                'companies' => ['Cloudbase Nordic'],
                'cf' => [
                    $jobTitle->key => 'Head of Infrastructure',
                    $linkedin->key => 'https://linkedin.com/in/sarahjohansson',
                    $contractType->key => $this->optionValue($contractType, 'Time & Materials'),
                    $techStack->key => $this->optionValues($techStack, ['AWS', 'Docker / Kubernetes', 'Python']),
                    $hourlyRate->key => 135,
                    $contractStart->key => '2024-09-01',
                    $contractEnd->key => '2026-03-31',
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Signed'),
                    $notes->key => 'Monthly invoicing. AWS cost optimisation ongoing.',
                ],
            ],
            [
                'name' => 'Damien Leroy',
                'email' => 'damien.leroy@hexasys.fr',
                'phone' => '+33 1 42 86 57 30',
                'tags' => ['Prospect', 'Management'],
                'companies' => ['HexaSys'],
                'cf' => [
                    $jobTitle->key => 'VP Engineering',
                    $linkedin->key => 'https://linkedin.com/in/damienleroy',
                    $contractType->key => $this->optionValue($contractType, 'Project-Based'),
                    $techStack->key => $this->optionValues($techStack, ['React', 'JavaScript / Node.js', 'PostgreSQL']),
                    $hourlyRate->key => 120,
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Pending'),
                    $notes->key => 'Intro call done. Awaiting NDA before sending proposal.',
                ],
            ],
            [
                'name' => 'Priya Nair',
                'email' => 'priya.nair@securepeak.com',
                'phone' => '+44 7700 900 123',
                'tags' => ['Client', 'Security', 'VIP'],
                'companies' => ['SecurePeak Ltd'],
                'cf' => [
                    $jobTitle->key => 'CISO',
                    $linkedin->key => 'https://linkedin.com/in/priyanair',
                    $contractType->key => $this->optionValue($contractType, 'Retainer'),
                    $techStack->key => $this->optionValues($techStack, ['AWS', 'Azure']),
                    $hourlyRate->key => 200,
                    $contractStart->key => '2024-03-01',
                    $contractEnd->key => '2026-02-28',
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Signed'),
                    $notes->key => 'Security audit retainer. Quarterly report deliverable.',
                ],
            ],
            [
                'name' => 'Lars Müller',
                'email' => 'lars.muller@devstudio.de',
                'phone' => '+49 30 1234 5678',
                'tags' => ['Lead', 'Technical'],
                'companies' => ['DevStudio Berlin'],
                'cf' => [
                    $jobTitle->key => 'Lead Developer',
                    $contractType->key => $this->optionValue($contractType, 'Fixed Price'),
                    $techStack->key => $this->optionValues($techStack, ['Vue.js', 'PHP / Laravel', 'PostgreSQL']),
                    $hourlyRate->key => 110,
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Not Required'),
                    $notes->key => 'Referred by Marcus Chen. Looking for a long-term partner.',
                ],
            ],
            [
                'name' => 'Amara Diallo',
                'email' => 'amara.diallo@innotech-sa.com',
                'phone' => '+41 76 543 2100',
                'tags' => ['Prospect', 'Management'],
                'companies' => ['InnoTech SA'],
                'cf' => [
                    $jobTitle->key => 'Digital Transformation Director',
                    $linkedin->key => 'https://linkedin.com/in/amaradiallo',
                    $contractType->key => $this->optionValue($contractType, 'Project-Based'),
                    $techStack->key => $this->optionValues($techStack, ['Azure', 'Python']),
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Pending'),
                    $notes->key => 'Large digital transformation project. Budget TBD.',
                ],
            ],
            [
                'name' => 'Tom Whitfield',
                'email' => 'tom.whitfield@stackops.io',
                'phone' => '+44 20 7946 0958',
                'tags' => ['Client', 'DevOps', 'Technical'],
                'companies' => ['StackOps'],
                'cf' => [
                    $jobTitle->key => 'Platform Engineer',
                    $contractType->key => $this->optionValue($contractType, 'Time & Materials'),
                    $techStack->key => $this->optionValues($techStack, ['Docker / Kubernetes', 'AWS', 'Python']),
                    $hourlyRate->key => 130,
                    $contractStart->key => '2025-03-01',
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Signed'),
                ],
            ],
            [
                'name' => 'Nadia Petrova',
                'email' => 'nadia.petrova@softbridge.eu',
                'phone' => '+31 6 87654321',
                'tags' => ['Lead', 'Technical'],
                'companies' => ['SoftBridge Europe'],
                'cf' => [
                    $jobTitle->key => 'Senior Backend Developer',
                    $linkedin->key => 'https://linkedin.com/in/nadiapetrova',
                    $contractType->key => $this->optionValue($contractType, 'Project-Based'),
                    $techStack->key => $this->optionValues($techStack, ['PHP / Laravel', 'PostgreSQL', 'React']),
                    $hourlyRate->key => 95,
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Not Required'),
                    $notes->key => 'Interested in a 3-month Laravel API project.',
                ],
            ],
            [
                'name' => 'James Okafor',
                'email' => 'james.okafor@cybernode.ng',
                'phone' => '+234 802 345 6789',
                'tags' => ['Cold'],
                'companies' => ['CyberNode Africa'],
                'cf' => [
                    $jobTitle->key => 'Product Manager',
                    $contractType->key => $this->optionValue($contractType, 'Project-Based'),
                    $techStack->key => $this->optionValues($techStack, ['JavaScript / Node.js', 'React']),
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Not Required'),
                    $notes->key => 'Initial contact at WebSummit. Follow up Q2 2026.',
                ],
            ],
            [
                'name' => 'Elena Vasquez',
                'email' => 'elena.vasquez@pixelcraft.studio',
                'phone' => '+34 612 345 678',
                'tags' => ['Partner'],
                'companies' => ['PixelCraft Studio'],
                'cf' => [
                    $jobTitle->key => 'Design Director',
                    $linkedin->key => 'https://linkedin.com/in/elenavasquez',
                    $contractType->key => $this->optionValue($contractType, 'Project-Based'),
                    $techStack->key => $this->optionValues($techStack, ['Vue.js', 'React']),
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Signed'),
                    $notes->key => 'Subcontractor for front-end. Strong Vue.js skills.',
                ],
            ],
            [
                'name' => 'Hugo Fernandes',
                'email' => 'hugo.fernandes@datalab.pt',
                'phone' => '+351 91 234 5678',
                'tags' => ['Prospect', 'Technical'],
                'companies' => ['DataLab Portugal'],
                'cf' => [
                    $jobTitle->key => 'Data Engineer',
                    $contractType->key => $this->optionValue($contractType, 'Time & Materials'),
                    $techStack->key => $this->optionValues($techStack, ['Python', 'PostgreSQL', 'AWS']),
                    $hourlyRate->key => 100,
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Pending'),
                ],
            ],
            [
                'name' => 'Yasmin Al-Rashid',
                'email' => 'yasmin.alrashid@cloudshift.ae',
                'phone' => '+971 50 123 4567',
                'tags' => ['Lead', 'Management', 'VIP'],
                'companies' => ['CloudShift MENA'],
                'cf' => [
                    $jobTitle->key => 'IT Director',
                    $linkedin->key => 'https://linkedin.com/in/yasminrashid',
                    $contractType->key => $this->optionValue($contractType, 'Retainer'),
                    $techStack->key => $this->optionValues($techStack, ['Azure', 'Docker / Kubernetes']),
                    $hourlyRate->key => 175,
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Pending'),
                    $notes->key => 'Referred by Priya Nair. High-value opportunity.',
                ],
            ],
            [
                'name' => 'Florian Dupont',
                'email' => 'florian.dupont@freelance.io',
                'phone' => '+32 475 123 456',
                'tags' => ['Partner', 'DevOps'],
                'companies' => ['StackOps', 'PixelCraft Studio'],
                'cf' => [
                    $jobTitle->key => 'DevOps Consultant',
                    $contractType->key => $this->optionValue($contractType, 'Time & Materials'),
                    $techStack->key => $this->optionValues($techStack, ['Docker / Kubernetes', 'AWS', 'Python']),
                    $hourlyRate->key => 115,
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Signed'),
                    $notes->key => 'Available for overflow work. Max 2 days/week.',
                ],
            ],
            [
                'name' => 'Ingrid Svensson',
                'email' => 'ingrid.svensson@nordicsec.com',
                'phone' => '+46 8 123 456 78',
                'tags' => ['Client', 'Security'],
                'companies' => ['Nordic Security AB'],
                'cf' => [
                    $jobTitle->key => 'Security Analyst',
                    $contractType->key => $this->optionValue($contractType, 'Project-Based'),
                    $techStack->key => $this->optionValues($techStack, ['Python', 'AWS']),
                    $hourlyRate->key => 145,
                    $contractStart->key => '2025-06-01',
                    $contractEnd->key => '2025-11-30',
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Signed'),
                    $notes->key => 'Pen testing engagement. Deliverable: report + remediation plan.',
                ],
            ],
            [
                'name' => 'Remy Bertrand',
                'email' => 'remy.bertrand@webscale.fr',
                'phone' => null,
                'tags' => ['Cold'],
                'companies' => ['WebScale Paris'],
                'cf' => [
                    $jobTitle->key => 'Engineering Manager',
                    $linkedin->key => 'https://linkedin.com/in/remybertrand',
                    $ndaStatus->key => $this->optionValue($ndaStatus, 'Not Required'),
                    $notes->key => 'Met at Paris JS conference. No immediate need.',
                ],
            ],
        ];

        foreach ($contactsData as $data) {
            if (Contact::where('organization_id', $org->id)->where('email', $data['email'])->exists()) {
                continue;
            }

            $contact = Contact::create([
                'organization_id' => $org->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'owner_id' => $data['email'] === 'amara.diallo@innotech-sa.com' ? null : $user->id,
                'custom_field_values' => $data['cf'],
            ]);

            $tagIds = collect($data['tags'])
                ->map(fn (string $name) => $tags[$name]->id)
                ->all();

            $contact->tags()->sync($tagIds);
        }

        // ── Companies ────────────────────────────────────────────────────────
        $companies = $this->seedCompanies($org, $user);

        foreach ($contactsData as $data) {
            $contact = Contact::where('organization_id', $org->id)->where('email', $data['email'])->first();

            $companyIds = collect($data['companies'] ?? [])
                ->map(fn (string $name) => $companies[$name]->id)
                ->all();

            $contact?->companies()->syncWithoutDetaching($companyIds);
        }

        // ── Activities ───────────────────────────────────────────────────────
        $contacts = Contact::where('organization_id', $org->id)->get()->keyBy('email');

        $activitiesData = [
            ['email' => 'marcus.chen@techcorp.io', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-10 09:30:00', 'duration_minutes' => 45, 'subject' => 'Quarterly retainer review', 'notes' => 'Discussed Q1 deliverables and upcoming infrastructure migration. Very satisfied with progress.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'marcus.chen@techcorp.io', 'type' => ActivityType::Email, 'occurred_at' => '2026-02-12 14:15:00', 'subject' => 'Migration timeline proposal', 'notes' => 'Sent updated timeline for AWS migration. Awaiting feedback.'],
            ['email' => 'marcus.chen@techcorp.io', 'type' => ActivityType::Meeting, 'occurred_at' => '2026-01-20 10:00:00', 'duration_minutes' => 90, 'subject' => 'Annual strategy session', 'notes' => 'Full-day planning session for 2026 roadmap. Budget approved for Phase 2.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'sarah.johansson@cloudbase.dev', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-14 11:00:00', 'duration_minutes' => 30, 'subject' => 'AWS cost review', 'notes' => 'Reviewed last month invoices. Found 15% saving opportunity on reserved instances.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'sarah.johansson@cloudbase.dev', 'type' => ActivityType::WhatsApp, 'occurred_at' => '2026-02-16 08:45:00', 'notes' => 'Quick check-in about the staging cluster issue. Resolved overnight.'],
            ['email' => 'damien.leroy@hexasys.fr', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-05 15:00:00', 'duration_minutes' => 25, 'subject' => 'Intro call', 'notes' => 'Discussed their React migration needs. Interested in a 6-month engagement.', 'outcome' => ActivityOutcome::FollowUpNeeded],
            ['email' => 'damien.leroy@hexasys.fr', 'type' => ActivityType::Email, 'occurred_at' => '2026-02-06 10:30:00', 'subject' => 'NDA sent for signature', 'notes' => 'Sent NDA via DocuSign. Expected turnaround: 2 business days.'],
            ['email' => 'priya.nair@securepeak.com', 'type' => ActivityType::Meeting, 'occurred_at' => '2026-02-03 14:00:00', 'duration_minutes' => 60, 'subject' => 'Q4 2025 security audit report', 'notes' => 'Presented findings. 3 critical vulnerabilities patched, 12 medium resolved.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'priya.nair@securepeak.com', 'type' => ActivityType::Email, 'occurred_at' => '2026-02-10 09:00:00', 'subject' => 'Remediation plan v2', 'notes' => 'Updated plan after feedback. Deadline pushed to March 15.'],
            ['email' => 'lars.muller@devstudio.de', 'type' => ActivityType::InPerson, 'occurred_at' => '2026-01-28 12:00:00', 'duration_minutes' => 75, 'subject' => 'Lunch meeting in Berlin', 'notes' => 'Met at their office. Great cultural fit. They want a Laravel + Vue stack for their new product.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'amara.diallo@innotech-sa.com', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-11 16:00:00', 'duration_minutes' => 40, 'subject' => 'Discovery call', 'notes' => 'Large-scale Azure migration. Budget range 200k-350k EUR. Decision expected Q2.', 'outcome' => ActivityOutcome::FollowUpNeeded],
            ['email' => 'tom.whitfield@stackops.io', 'type' => ActivityType::WhatsApp, 'occurred_at' => '2026-02-15 17:30:00', 'notes' => 'Confirmed next sprint planning for Monday 10 AM.'],
            ['email' => 'tom.whitfield@stackops.io', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-13 10:00:00', 'duration_minutes' => 20, 'subject' => 'Sprint retrospective', 'notes' => 'Good velocity this sprint. One blocker on CI pipeline resolved.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'nadia.petrova@softbridge.eu', 'type' => ActivityType::Email, 'occurred_at' => '2026-02-07 11:00:00', 'subject' => 'Laravel API project proposal', 'notes' => 'Sent 3-month project proposal with milestones and pricing.', 'outcome' => ActivityOutcome::Neutral],
            ['email' => 'james.okafor@cybernode.ng', 'type' => ActivityType::Note, 'occurred_at' => '2026-01-15 09:00:00', 'subject' => 'WebSummit follow-up reminder', 'notes' => 'Met at WebSummit booth. Expressed interest in a React/Node project. Follow up in Q2 2026.'],
            ['email' => 'elena.vasquez@pixelcraft.studio', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-09 14:00:00', 'duration_minutes' => 15, 'subject' => 'Availability check', 'notes' => 'Available for 3 days/week starting March. Confirmed rate.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'yasmin.alrashid@cloudshift.ae', 'type' => ActivityType::Meeting, 'occurred_at' => '2026-02-04 11:00:00', 'duration_minutes' => 60, 'subject' => 'Virtual intro meeting', 'notes' => 'Referred by Priya. Looking for a managed cloud services partner. Very promising.', 'outcome' => ActivityOutcome::FollowUpNeeded],
            ['email' => 'ingrid.svensson@nordicsec.com', 'type' => ActivityType::Email, 'occurred_at' => '2026-02-08 16:00:00', 'subject' => 'Pen test scope document', 'notes' => 'Sent scope document for the June engagement. Includes external and internal pen testing.'],
            ['email' => 'ingrid.svensson@nordicsec.com', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-12 10:00:00', 'duration_minutes' => 30, 'subject' => 'Scope review call', 'notes' => 'Reviewed scope doc together. Added social engineering component per their request.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'remy.bertrand@webscale.fr', 'type' => ActivityType::Note, 'occurred_at' => '2026-01-22 09:00:00', 'notes' => 'No immediate need. Re-evaluate in 6 months.'],
        ];

        foreach ($activitiesData as $actData) {
            $contact = $contacts[$actData['email']] ?? null;

            if (! $contact) {
                continue;
            }

            Activity::firstOrCreate(
                [
                    'contact_id' => $contact->id,
                    'type' => $actData['type'],
                    'occurred_at' => $actData['occurred_at'],
                ],
                [
                    'organization_id' => $org->id,
                    'user_id' => $user->id,
                    'duration_minutes' => $actData['duration_minutes'] ?? null,
                    'subject' => $actData['subject'] ?? null,
                    'notes' => $actData['notes'] ?? null,
                    'outcome' => $actData['outcome'] ?? null,
                ]
            );
        }

        // ── Deals ────────────────────────────────────────────────────────────
        $dealsData = [
            ['title' => 'TechCorp Infra Expansion', 'email' => 'marcus.chen@techcorp.io', 'stage' => 'Negotiating', 'status' => DealStatus::Open, 'value' => 85000, 'expected_close_date' => now()->addDays(9)->toDateString(), 'notes' => 'Final legal review before signature.'],
            ['title' => 'Cloudbase Cost Optimization Retainer', 'email' => 'sarah.johansson@cloudbase.dev', 'stage' => 'Proposal Sent', 'status' => DealStatus::Open, 'value' => 42000, 'expected_close_date' => now()->addDays(5)->toDateString(), 'notes' => 'Proposal sent, waiting for finance sign-off.'],
            ['title' => 'HexaSys React Platform Migration', 'email' => 'damien.leroy@hexasys.fr', 'stage' => 'Discovery', 'status' => DealStatus::Open, 'value' => 65000, 'expected_close_date' => now()->addDays(18)->toDateString(), 'notes' => 'Technical workshops scheduled next week.'],
            ['title' => 'SecurePeak Annual Security Program', 'email' => 'priya.nair@securepeak.com', 'stage' => 'Won', 'status' => DealStatus::Won, 'value' => 120000, 'expected_close_date' => now()->subDays(12)->toDateString(), 'won_at' => now()->subDays(10), 'notes' => 'Contract signed and onboarded.'],
            ['title' => 'InnoTech Transformation Advisory', 'email' => 'amara.diallo@innotech-sa.com', 'stage' => 'Lead', 'status' => DealStatus::Open, 'no_owner' => true, 'value' => 30000, 'expected_close_date' => now()->addDays(24)->toDateString(), 'notes' => 'Initial qualification in progress.'],
            ['title' => 'NordicSec Pen Testing Extension', 'email' => 'ingrid.svensson@nordicsec.com', 'stage' => 'Lost', 'status' => DealStatus::Lost, 'value' => 38000, 'expected_close_date' => now()->subDays(20)->toDateString(), 'lost_at' => now()->subDays(18), 'notes' => 'Budget reallocated to internal team.'],
        ];

        $stages = DefaultPipeline::stagesOf($org->id)->keyBy('name');

        foreach ($dealsData as $dealData) {
            $contact = $contacts[$dealData['email']] ?? null;

            if (! $contact) {
                continue;
            }

            Deal::firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'title' => $dealData['title'],
                ],
                [
                    'contact_id' => $contact->id,
                    'company_id' => DealCompany::defaultIdFor($contact->id),
                    'pipeline_stage_id' => $stages[$dealData['stage']]->getKey(),
                    'status' => $dealData['status'],
                    'value' => $dealData['value'],
                    'expected_close_date' => $dealData['expected_close_date'],
                    'notes' => $dealData['notes'],
                    'won_at' => $dealData['won_at'] ?? null,
                    'lost_at' => $dealData['lost_at'] ?? null,
                    'created_by' => $user->id,
                    'owner_id' => ($dealData['no_owner'] ?? false) ? null : $user->id,
                ]
            );
        }

        $this->seedLostReasons($org);
        $this->seedClosedDealHistory($org);

        // ── Invoices ─────────────────────────────────────────────────────────
        $invoicesData = [
            ['invoice_number' => 'INV-2026-001', 'email' => 'marcus.chen@techcorp.io', 'deal_title' => 'TechCorp Infra Expansion', 'amount' => 24000, 'status' => InvoiceStatus::Paid, 'issued_at' => now()->subDays(20)->toDateString(), 'due_at' => now()->subDays(5)->toDateString(), 'paid_at' => now()->subDays(6)->toDateString(), 'notes' => 'Phase 1 infrastructure migration milestone.'],
            ['invoice_number' => 'INV-2026-002', 'email' => 'sarah.johansson@cloudbase.dev', 'deal_title' => 'Cloudbase Cost Optimization Retainer', 'amount' => 7800, 'status' => InvoiceStatus::Sent, 'issued_at' => now()->subDays(9)->toDateString(), 'due_at' => now()->addDays(7)->toDateString(), 'notes' => 'Monthly retainer February.'],
            ['invoice_number' => 'INV-2026-003', 'email' => 'damien.leroy@hexasys.fr', 'deal_title' => 'HexaSys React Platform Migration', 'amount' => 5600, 'amount_paid' => 2000, 'status' => InvoiceStatus::Partial, 'issued_at' => now()->subDays(18)->toDateString(), 'due_at' => now()->subDays(2)->toDateString(), 'paid_at' => now()->subDays(1)->toDateString(), 'notes' => 'Deposit received, awaiting final transfer.'],
            ['invoice_number' => 'INV-2026-004', 'email' => 'ingrid.svensson@nordicsec.com', 'deal_title' => 'NordicSec Pen Testing Extension', 'amount' => 9200, 'status' => InvoiceStatus::Overdue, 'issued_at' => now()->subDays(26)->toDateString(), 'due_at' => now()->subDays(8)->toDateString(), 'notes' => 'Reminder sent twice.'],
            ['invoice_number' => 'INV-2026-005', 'email' => 'priya.nair@securepeak.com', 'deal_title' => 'SecurePeak Annual Security Program', 'amount' => 31000, 'status' => InvoiceStatus::Paid, 'issued_at' => now()->subDays(40)->toDateString(), 'due_at' => now()->subDays(25)->toDateString(), 'paid_at' => now()->subDays(24)->toDateString(), 'notes' => 'Annual security program Q1 billing.'],
            ['invoice_number' => 'INV-2026-006', 'email' => 'amara.diallo@innotech-sa.com', 'deal_title' => null, 'amount' => 3500, 'status' => InvoiceStatus::Draft, 'issued_at' => now()->toDateString(), 'due_at' => now()->addDays(14)->toDateString(), 'notes' => 'Draft invoice for discovery workshops.'],
        ];

        foreach ($invoicesData as $invoiceData) {
            $contact = $contacts[$invoiceData['email']] ?? null;

            if (! $contact) {
                continue;
            }

            $deal = null;

            if ($invoiceData['deal_title']) {
                $deal = Deal::query()
                    ->where('organization_id', $org->id)
                    ->where('title', $invoiceData['deal_title'])
                    ->first();
            }

            $invoice = Invoice::firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'invoice_number' => $invoiceData['invoice_number'],
                ],
                [
                    'contact_id' => $contact->id,
                    'deal_id' => $deal?->id,
                    'amount' => $invoiceData['amount'],
                    'amount_paid' => $invoiceData['amount_paid'] ?? 0,
                    'status' => $invoiceData['status'],
                    'issued_at' => $invoiceData['issued_at'],
                    'due_at' => $invoiceData['due_at'],
                    'paid_at' => $invoiceData['paid_at'] ?? null,
                    'notes' => $invoiceData['notes'] ?? null,
                    'created_by' => $user->id,
                ]
            );

            if ((float) $invoice->amount_paid > 0 && ! $invoice->payments()->exists()) {
                InvoicePayment::create([
                    'organization_id' => $org->id,
                    'invoice_id' => $invoice->id,
                    'kind' => InvoicePaymentKind::Payment,
                    'amount' => $invoice->amount_paid,
                    'paid_on' => $invoice->paid_at,
                    'method' => PaymentMethod::BankTransfer,
                    'reference' => null,
                    'recorded_by_id' => null,
                ]);
            }
        }

        $this->seedInvoiceHistory($org);

        // ── Tasks ────────────────────────────────────────────────────────────
        $tasksData = [
            ['title' => 'Finalize TechCorp contract redlines', 'email' => 'marcus.chen@techcorp.io', 'type' => TaskType::FollowUp, 'priority' => TaskPriority::High, 'status' => TaskStatus::Pending, 'due_at' => now()->addDay(), 'notes' => 'Review legal comments and send final draft.'],
            ['title' => 'Call Sarah for proposal approval', 'email' => 'sarah.johansson@cloudbase.dev', 'type' => TaskType::Call, 'priority' => TaskPriority::Medium, 'status' => TaskStatus::Pending, 'due_at' => now()->addDays(2), 'notes' => 'Confirm timeline and procurement process.'],
            ['title' => 'Prepare HexaSys discovery workshop agenda', 'email' => 'damien.leroy@hexasys.fr', 'type' => TaskType::Meeting, 'priority' => TaskPriority::High, 'status' => TaskStatus::Pending, 'due_at' => now()->addDays(3), 'notes' => 'Share architecture questionnaire in advance.'],
            ['title' => 'Send SecurePeak kickoff summary', 'email' => 'priya.nair@securepeak.com', 'type' => TaskType::Email, 'priority' => TaskPriority::Low, 'status' => TaskStatus::Done, 'due_at' => now()->subDays(2), 'completed_at' => now()->subDay(), 'notes' => 'Kickoff notes and next milestones sent.'],
            ['title' => 'Invoice StackOps sprint support', 'email' => 'tom.whitfield@stackops.io', 'type' => TaskType::Invoice, 'priority' => TaskPriority::Medium, 'status' => TaskStatus::Pending, 'due_at' => now()->addDays(5), 'notes' => 'Prepare invoice for February sprint support.', 'no_owner' => true],
            ['title' => 'Follow up with Yasmin on budget confirmation', 'email' => 'yasmin.alrashid@cloudshift.ae', 'type' => TaskType::FollowUp, 'priority' => TaskPriority::High, 'status' => TaskStatus::Pending, 'due_at' => now()->addDays(4), 'notes' => 'Clarify budget owner and procurement steps.'],
        ];

        foreach ($tasksData as $taskData) {
            $contact = $contacts[$taskData['email']] ?? null;

            if (! $contact) {
                continue;
            }

            Task::firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'title' => $taskData['title'],
                ],
                [
                    'contact_id' => $contact->id,
                    'created_by' => $user->id,
                    'owner_id' => ($taskData['no_owner'] ?? false) ? null : $user->id,
                    'type' => $taskData['type'],
                    'priority' => $taskData['priority'],
                    'status' => $taskData['status'],
                    'due_at' => $taskData['due_at'],
                    'completed_at' => $taskData['completed_at'] ?? null,
                    'notes' => $taskData['notes'] ?? null,
                ]
            );
        }

        $this->seedRecentActivitiesAndTasks($org);

        // ── Segments ─────────────────────────────────────────────────────────
        $this->seedSegments($org, $tags, $linkedin, $techStack, $hourlyRate);
    }

    /**
     * Activities and completed tasks of the three role users over the last 90 days, dated from today, so the team and period reports have figures.
     */
    private function seedRecentActivitiesAndTasks(Organization $org): void
    {
        $today = now()->toImmutable();
        $at = fn (int $daysAgo, string $time): CarbonImmutable => $today->subDays($daysAgo)->setTimeFromTimeString($time);

        $test = 'test@example.com';
        $admin = 'admin@example.com';
        $member = 'member@example.com';

        $activities = [
            [2, '09:30', $test, 'marcus.chen@techcorp.io', ActivityType::Call, 30, 'Contract redlines walkthrough', 'Went through the last legal comments line by line.', ActivityOutcome::Positive],
            [6, '14:00', $test, 'sarah.johansson@cloudbase.dev', ActivityType::Email, null, 'Finance sign-off reminder', 'Asked for the expected date of the finance sign-off.', null],
            [11, '10:00', $test, 'yasmin.alrashid@cloudshift.ae', ActivityType::Meeting, 45, 'Budget owner introduction', 'Met the budget owner; procurement steps still unclear.', ActivityOutcome::FollowUpNeeded],
            [19, '16:30', $test, 'priya.nair@securepeak.com', ActivityType::Call, 20, 'Onboarding check-in', 'First month of the annual program going to plan.', ActivityOutcome::Positive],
            [37, '11:00', $test, 'nadia.petrova@softbridge.eu', ActivityType::Meeting, 60, 'API build kickoff', 'Kickoff of the Laravel API build with their backend team.', ActivityOutcome::Positive],
            [44, '09:00', $test, 'marcus.chen@techcorp.io', ActivityType::Email, null, 'Security audit follow-up', 'Sent the remediation checklist from the audit.', ActivityOutcome::Neutral],
            [53, '15:00', $test, 'priya.nair@securepeak.com', ActivityType::InPerson, 90, 'Cloud posture review workshop', 'On-site workshop on the posture review findings.', ActivityOutcome::Positive],
            [61, '10:30', $test, 'yasmin.alrashid@cloudshift.ae', ActivityType::Call, 40, 'Pilot scope discussion', 'Discussed the scope of the managed services pilot.', ActivityOutcome::FollowUpNeeded],
            [72, '13:00', $test, 'james.okafor@cybernode.ng', ActivityType::Email, null, 'Q3 plans follow-up', 'No project planned before next year.', ActivityOutcome::Negative],
            [84, '11:30', $test, 'remy.bertrand@webscale.fr', ActivityType::Note, null, 'Performance audit interest', 'Asked about a performance audit; to be qualified.', null],
            [3, '10:00', $admin, 'damien.leroy@hexasys.fr', ActivityType::Call, 25, 'Workshop agenda review', 'Agreed on the agenda of the discovery workshops.', ActivityOutcome::Positive],
            [8, '15:30', $admin, 'lars.muller@devstudio.de', ActivityType::WhatsApp, null, 'Partnership check-in', 'Quick check-in on the front-end partnership.', null],
            [15, '09:00', $admin, 'sarah.johansson@cloudbase.dev', ActivityType::Meeting, 60, 'Kubernetes migration retrospective', 'Retrospective of the migration; two follow-up items.', ActivityOutcome::Positive],
            [24, '11:00', $admin, 'damien.leroy@hexasys.fr', ActivityType::Email, null, 'Code review retainer recap', 'Recap of why the retainer was declined.', ActivityOutcome::Neutral],
            [40, '14:00', $admin, 'lars.muller@devstudio.de', ActivityType::Meeting, 45, 'Partnership terms review', 'Reviewed rates and capacity for the partnership.', ActivityOutcome::Positive],
            [47, '10:00', $admin, 'james.okafor@cybernode.ng', ActivityType::Call, 30, 'Mobile app proposal call', 'Walked through the mobile app proposal.', ActivityOutcome::FollowUpNeeded],
            [66, '16:00', $admin, 'sarah.johansson@cloudbase.dev', ActivityType::Email, null, 'Migration status report', 'Sent the weekly migration status report.', null],
            [79, '09:30', $admin, 'damien.leroy@hexasys.fr', ActivityType::Message, null, 'Code review offer', 'Introduced the code review retainer offer.', null],
            [4, '11:00', $member, 'tom.whitfield@stackops.io', ActivityType::Call, 20, 'Pipeline hardening follow-up', 'Checked the hardened CI pipeline after a month.', ActivityOutcome::Positive],
            [13, '14:30', $member, 'elena.vasquez@pixelcraft.studio', ActivityType::Email, null, 'Support hours summary', 'Sent the design system support hours of the month.', ActivityOutcome::Neutral],
            [26, '10:00', $member, 'tom.whitfield@stackops.io', ActivityType::WhatsApp, null, 'Sprint planning confirmation', 'Confirmed the next sprint planning slot.', null],
            [51, '13:30', $member, 'elena.vasquez@pixelcraft.studio', ActivityType::Meeting, 30, 'Design system support kickoff', 'Kickoff of the design system support.', ActivityOutcome::Positive],
            [69, '09:00', $member, 'ingrid.svensson@nordicsec.com', ActivityType::Call, 15, 'Pen test extension follow-up', 'They keep the extension in-house.', ActivityOutcome::Negative],
            [87, '15:00', $member, 'tom.whitfield@stackops.io', ActivityType::Email, null, 'CI hardening proposal', 'Sent the CI pipeline hardening proposal.', null],
        ];

        $tasks = [
            ['Send TechCorp redlined contract', 'marcus.chen@techcorp.io', $test, TaskType::Email, TaskPriority::High, 2, 2, '10:15', 'Redlined contract sent to legal.'],
            ['Book CloudShift budget meeting', 'yasmin.alrashid@cloudshift.ae', $test, TaskType::Meeting, TaskPriority::Medium, 12, 12, '09:00', 'Meeting booked with the budget owner.'],
            ['Prepare SoftBridge kickoff deck', 'nadia.petrova@softbridge.eu', $test, TaskType::Meeting, TaskPriority::High, 38, 38, '16:00', 'Deck shared before the kickoff.'],
            ['Follow up SecurePeak posture findings', 'priya.nair@securepeak.com', $test, TaskType::FollowUp, TaskPriority::Medium, 57, 55, '11:00', 'Findings sent two days late.'],
            ['Call CyberNode about Q3 plans', 'james.okafor@cybernode.ng', $test, TaskType::Call, TaskPriority::Low, 72, 72, '14:00', 'No project before next year.'],
            ['Share HexaSys architecture questionnaire', 'damien.leroy@hexasys.fr', $admin, TaskType::Email, TaskPriority::High, 5, 5, '09:45', 'Questionnaire sent ahead of the workshops.'],
            ['Invoice Cloudbase migration milestone', 'sarah.johansson@cloudbase.dev', $admin, TaskType::Invoice, TaskPriority::Medium, 16, 15, '15:00', 'Milestone invoice prepared.'],
            ['Review DevStudio partnership terms', 'lars.muller@devstudio.de', $admin, TaskType::FollowUp, TaskPriority::Medium, 41, 41, '12:30', 'Terms reviewed with their lead developer.'],
            ['Send CyberNode mobile app estimate', 'james.okafor@cybernode.ng', $admin, TaskType::Email, TaskPriority::High, 48, 46, '10:00', 'Estimate sent after the proposal call.'],
            ['Confirm StackOps sprint planning', 'tom.whitfield@stackops.io', $member, TaskType::Call, TaskPriority::Low, 4, 4, '11:30', 'Sprint planning confirmed.'],
            ['Send PixelCraft support hours summary', 'elena.vasquez@pixelcraft.studio', $member, TaskType::Email, TaskPriority::Medium, 14, 13, '14:45', 'Summary sent one day after the due date.'],
            ['Call NordicSec about the extension', 'ingrid.svensson@nordicsec.com', $member, TaskType::Call, TaskPriority::Medium, 70, 69, '09:15', 'Extension kept in-house.'],
        ];

        $users = User::query()->whereIn('email', [$test, $admin, $member])->pluck('id', 'email');
        $contacts = Contact::query()
            ->whereIn('email', [...array_column($activities, 3), ...array_column($tasks, 1)])
            ->get()
            ->keyBy('email');

        foreach ($activities as [$daysAgo, $time, $userEmail, $contactEmail, $type, $duration, $subject, $notes, $outcome]) {
            Activity::firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'contact_id' => $contacts[$contactEmail]->id,
                    'subject' => $subject,
                ],
                [
                    'user_id' => $users[$userEmail],
                    'deal_id' => null,
                    'type' => $type,
                    'occurred_at' => $at($daysAgo, $time),
                    'duration_minutes' => $duration,
                    'notes' => $notes,
                    'outcome' => $outcome,
                ]
            );
        }

        foreach ($tasks as [$title, $contactEmail, $userEmail, $type, $priority, $dueDaysAgo, $completedDaysAgo, $completedTime, $notes]) {
            Task::firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'title' => $title,
                ],
                [
                    'contact_id' => $contacts[$contactEmail]->id,
                    'deal_id' => null,
                    'created_by' => $users[$userEmail],
                    'owner_id' => $users[$userEmail],
                    'type' => $type,
                    'priority' => $priority,
                    'status' => TaskStatus::Done,
                    'due_at' => $at($dueDaysAgo, '17:00'),
                    'completed_at' => $at($completedDaysAgo, $completedTime),
                    'notes' => $notes,
                ]
            );
        }
    }

    /**
     * Nine invoices issued over the months before the current one, paid at their own dates through the invoice ledger
     * (one in two instalments, one partial after a deposit, one cancelled and refunded), plus overdue ones for every
     * aging bucket; an invoice that already exists is skipped.
     */
    private function seedInvoiceHistory(Organization $org): void
    {
        $today = now()->toImmutable();
        $month = fn (int $monthsAgo, int $day): CarbonImmutable => $today->startOfMonth()->subMonthsNoOverflow($monthsAgo)->setDay($day)->setTime(12, 0);
        $daysAgo = fn (int $days): CarbonImmutable => $today->subDays($days)->setTime(12, 0);

        $test = 'test@example.com';
        $admin = 'admin@example.com';
        $member = 'member@example.com';
        $bank = PaymentMethod::BankTransfer;

        $rows = [
            ['INV-2026-007', 'marcus.chen@techcorp.io', 'TechCorp Security Audit', $test, 48000, $month(5, 20), $month(4, 19), InvoiceStatus::Sent, [['payment', '48000.00', $month(4, 15), $bank]], 'Security audit, full engagement.'],
            ['INV-2026-008', 'sarah.johansson@cloudbase.dev', 'Cloudbase Kubernetes Migration', $admin, 36000, $month(5, 28), $month(4, 27), InvoiceStatus::Sent, [['payment', '18000.00', $month(4, 25), $bank], ['payment', '18000.00', $month(3, 10), $bank]], 'Kubernetes migration, paid in two instalments.'],
            ['INV-2026-009', 'tom.whitfield@stackops.io', 'StackOps CI Pipeline Hardening', $member, 18000, $month(4, 10), $month(3, 10), InvoiceStatus::Sent, [['payment', '18000.00', $month(3, 8), $bank]], 'CI pipeline hardening.'],
            ['INV-2026-010', 'james.okafor@cybernode.ng', null, $admin, 4500, $daysAgo(130), $daysAgo(100), InvoiceStatus::Overdue, [], 'Mobile app discovery workshop.'],
            ['INV-2026-011', 'priya.nair@securepeak.com', 'SecurePeak Cloud Posture Review', $test, 27000, $month(3, 7), $month(2, 6), InvoiceStatus::Sent, [['payment', '27000.00', $month(2, 4), $bank]], 'Cloud posture review.'],
            ['INV-2026-012', 'remy.bertrand@webscale.fr', null, $member, 2400, $daysAgo(105), $daysAgo(75), InvoiceStatus::Sent, [], 'Performance audit pre-study.'],
            ['INV-2026-013', 'nadia.petrova@softbridge.eu', 'SoftBridge Laravel API Build', $test, 33000, $month(2, 12), $daysAgo(45), InvoiceStatus::Sent, [['payment', '10000.00', $month(2, 20), $bank]], 'Deposit received, balance overdue.'],
            ['INV-2026-014', 'yasmin.alrashid@cloudshift.ae', 'CloudShift Managed Services Pilot', $test, 7500, $month(2, 27), $month(1, 27), InvoiceStatus::Sent, [['payment', '7500.00', $month(2, 28), $bank], ['cancel', $month(1, 20)], ['refund', '7500.00', $month(1, 24), $bank]], 'Pilot deposit, refunded after the pilot was postponed.'],
            ['INV-2026-015', 'elena.vasquez@pixelcraft.studio', 'PixelCraft Design System Support', $member, 9500, $month(1, 9), $month(1, 28), InvoiceStatus::Sent, [['payment', '9500.00', $month(1, 26), PaymentMethod::Card]], 'Design system support.'],
        ];

        $users = User::query()->whereIn('email', [$test, $admin, $member])->get()->keyBy('email');
        $contacts = Contact::query()->whereIn('email', array_column($rows, 1))->get()->keyBy('email');
        $deals = Deal::query()->whereIn('title', array_filter(array_column($rows, 2)))->pluck('id', 'title');
        $ledger = app(InvoiceLedger::class);

        foreach ($rows as [$number, $email, $dealTitle, $creatorEmail, $amount, $issued, $due, $status, $steps, $notes]) {
            if (Invoice::query()->where('organization_id', $org->id)->where('invoice_number', $number)->exists()) {
                continue;
            }

            $creator = $users[$creatorEmail];

            $invoice = Carbon::withTestNow($issued, fn (): Invoice => Invoice::create([
                'organization_id' => $org->id,
                'invoice_number' => $number,
                'contact_id' => $contacts[$email]->id,
                'deal_id' => $dealTitle === null ? null : $deals[$dealTitle],
                'amount' => $amount,
                'status' => $status,
                'issued_at' => $issued->toDateString(),
                'due_at' => $due->toDateString(),
                'notes' => $notes,
                'created_by' => $creator->id,
            ]));

            foreach ($steps as $step) {
                Carbon::withTestNow($step[2] ?? $step[1], function () use ($step, $invoice, $ledger, $creator): void {
                    if ($step[0] === 'cancel') {
                        $invoice->update(['status' => InvoiceStatus::Cancelled]);

                        return;
                    }

                    $ledger->record(
                        $invoice,
                        $step[0] === 'refund' ? InvoicePaymentKind::Refund : InvoicePaymentKind::Payment,
                        $step[1],
                        $step[2]->toDateString(),
                        $step[3],
                        null,
                        $creator,
                    );
                });
            }
        }
    }

    /**
     * Twelve closed deals over the six months before the current one, moved through the pipeline at their own dates so
     * the stage history, won and lost dates and reports have a past. Each deal is created and moved under
     * `Carbon::withTestNow()`, through `DealStageMover` and the deal observer; a deal that already exists is skipped.
     */
    private function seedClosedDealHistory(Organization $org): void
    {
        $today = now()->toImmutable();
        $at = fn (int $monthsAgo, int $day): CarbonImmutable => $today->startOfMonth()->subMonthsNoOverflow($monthsAgo)->setDay($day)->setTime(12, 0);

        $test = 'test@example.com';
        $admin = 'admin@example.com';
        $member = 'member@example.com';

        $rows = [
            ['TechCorp Security Audit', 'marcus.chen@techcorp.io', $test, 48000, [['Lead', 6, 4], ['Discovery', 6, 12], ['Proposal Sent', 6, 25], ['Negotiating', 5, 8], ['Won', 5, 19]], null, null],
            ['Cloudbase Kubernetes Migration', 'sarah.johansson@cloudbase.dev', $admin, 36000, [['Lead', 6, 10], ['Discovery', 6, 20], ['Proposal Sent', 5, 6], ['Won', 5, 27]], null, null],
            ['StackOps CI Pipeline Hardening', 'tom.whitfield@stackops.io', $member, 18000, [['Lead', 5, 3], ['Proposal Sent', 5, 10], ['Negotiating', 5, 24], ['Won', 4, 9]], null, null],
            ['DataLab Warehouse Prototype', 'hugo.fernandes@datalab.pt', $member, 22000, [['Lead', 5, 12], ['Discovery', 5, 22], ['Lost', 4, 14]], 'Price too high', null],
            ['SecurePeak Cloud Posture Review', 'priya.nair@securepeak.com', $test, 27000, [['Lead', 4, 2], ['Discovery', 4, 8], ['Proposal Sent', 4, 17], ['Negotiating', 4, 24], ['Won', 3, 6]], null, null],
            ['CyberNode Mobile App', 'james.okafor@cybernode.ng', $admin, 54000, [['Lead', 4, 6], ['Discovery', 4, 20], ['Proposal Sent', 3, 4], ['Lost', 3, 18]], 'Went with a competitor', null],
            ['SoftBridge Laravel API Build', 'nadia.petrova@softbridge.eu', $test, 33000, [['Lead', 3, 2], ['Discovery', 3, 9], ['Proposal Sent', 3, 16], ['Negotiating', 3, 23], ['Won', 2, 11]], null, null],
            ['WebScale Performance Audit', 'remy.bertrand@webscale.fr', $member, 12000, [['Lead', 3, 11], ['Lost', 3, 25]], null, null],
            ['DevStudio Vue Front-end Partnership', 'lars.muller@devstudio.de', $admin, 41000, [['Lead', 2, 3], ['Discovery', 2, 10], ['Proposal Sent', 2, 19], ['Negotiating', 1, 5], ['Won', 1, 16]], null, null],
            ['CloudShift Managed Services Pilot', 'yasmin.alrashid@cloudshift.ae', $test, 75000, [['Lead', 2, 6], ['Discovery', 2, 16], ['Proposal Sent', 2, 26], ['Negotiating', 1, 9], ['Lost', 1, 20]], 'No budget', 'Pilot postponed to next fiscal year.'],
            ['PixelCraft Design System Support', 'elena.vasquez@pixelcraft.studio', $member, 9500, [['Lead', 2, 14], ['Proposal Sent', 2, 21], ['Won', 1, 8]], null, null],
            ['HexaSys Code Review Retainer', 'damien.leroy@hexasys.fr', $admin, 15000, [['Lead', 1, 2], ['Discovery', 1, 12], ['Lost', 1, 23]], 'Bad timing', null],
        ];

        $stages = DefaultPipeline::stagesOf($org->id)->keyBy('name');
        $owners = User::query()->whereIn('email', [$test, $admin, $member])->pluck('id', 'email');
        $contacts = Contact::query()->whereIn('email', array_column($rows, 1))->get()->keyBy('email');
        $reasons = LostReason::query()->pluck('id', 'name');

        foreach ($rows as [$title, $email, $ownerEmail, $value, $steps, $reason, $note]) {
            if (Deal::query()->where('organization_id', $org->id)->where('title', $title)->exists()) {
                continue;
            }

            $contact = $contacts[$email];
            $ownerId = $owners[$ownerEmail];
            $lastMoment = $at($steps[array_key_last($steps)][1], $steps[array_key_last($steps)][2]);
            [, $createdMonths, $createdDay] = $steps[0];

            $deal = Carbon::withTestNow($at($createdMonths, $createdDay), fn (): Deal => Deal::create([
                ...DealStageMover::attributesFor($stages['Lead']),
                'organization_id' => $org->id,
                'contact_id' => $contact->id,
                'company_id' => DealCompany::defaultIdFor($contact->id),
                'title' => $title,
                'value' => $value,
                'expected_close_date' => $lastMoment->toDateString(),
                'notes' => null,
                'created_by' => $ownerId,
                'owner_id' => $ownerId,
            ]));

            foreach (array_slice($steps, 1) as [$stageName, $months, $day]) {
                Carbon::withTestNow($at($months, $day), fn (): Deal => $stageName === 'Lost'
                    ? DealStageMover::moveToLost($deal, $reason === null ? null : $reasons[$reason], $note)
                    : DealStageMover::move($deal, $stages[$stageName]));
            }
        }
    }

    /**
     * The reasons the demo organization loses deals for, and the reason of its lost deal.
     */
    private function seedLostReasons(Organization $org): void
    {
        foreach (['Price too high', 'Went with a competitor', 'No budget', 'Bad timing', 'No response'] as $name) {
            LostReason::firstOrCreate(['organization_id' => $org->id, 'name' => $name]);
        }

        Deal::query()->where('title', 'NordicSec Pen Testing Extension')->firstOrFail()->update([
            'lost_reason_id' => LostReason::query()->where('name', 'No budget')->firstOrFail()->id,
            'lost_reason_note' => 'Budget reallocated to internal team.',
        ]);
    }

    /**
     * One segment per kind of condition, plus the scheduled (hourly and daily) ones and a draft, so manual tests can
     * rely on them after `migrate:fresh --seed`. Published segments are synced right away.
     *
     * @param  array<string, Tag>  $tags
     */
    private function seedSegments(Organization $org, array $tags, CustomField $linkedin, CustomField $techStack, CustomField $hourlyRate): void
    {
        $agency = CompanyType::query()->where('name', 'Agency')->firstOrFail();
        $condition = fn (SegmentConditionType $type, ?string $field, SegmentOperator $operator, array $value = []): SegmentConditionData => SegmentConditionData::make($type, $field, $operator, $value);
        $rule = fn (string $name, SegmentConditionData ...$conditions): array => (new SegmentRuleData((string) Str::ulid(), $name, $conditions))->toArray();

        $segments = [
            'VIP' => [
                $rule('VIP tag', $condition(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tags['VIP']->id]])),
                $rule(
                    'Security managers on LinkedIn',
                    $condition(SegmentConditionType::Tags, null, SegmentOperator::HasAllOf, ['values' => [$tags['Management']->id, $tags['Security']->id]]),
                    $condition(SegmentConditionType::CustomField, $linkedin->key, SegmentOperator::IsNotBlank),
                ),
            ],
            'Laravel developers' => [
                $rule('Tech Stack has Laravel', $condition(SegmentConditionType::CustomField, $techStack->key, SegmentOperator::ContainsAnyOf, ['values' => $this->optionValues($techStack, ['PHP / Laravel'])])),
            ],
            'Open deals' => [
                $rule('Has an open deal', $condition(SegmentConditionType::Deal, null, SegmentOperator::HasDeal, ['deal_statuses' => [DealStatus::Open->value]])),
            ],
            'Won customers' => [
                $rule('Has a won deal', $condition(SegmentConditionType::Deal, null, SegmentOperator::HasDeal, ['deal_stages' => [DefaultPipeline::stageOfType($org->id, DealStatus::Won)->id]])),
            ],
            'Agency contacts' => [
                $rule('Works at an agency', $condition(SegmentConditionType::Company, null, SegmentOperator::CompanyTypeIsAnyOf, ['values' => [$agency->id]])),
            ],
            'Never contacted' => [
                $rule('No activity logged', $condition(SegmentConditionType::Activity, null, SegmentOperator::HasNotHadActivity)),
            ],
            'Gone quiet (hourly)' => [
                $rule('Last activity over 30 days ago', $condition(SegmentConditionType::Activity, null, SegmentOperator::LastActivityMoreThanDaysAgo, ['days' => 30])),
            ],
            'Added recently (daily)' => [
                $rule('Created in the last 30 days', $condition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::WithinLastDays, ['days' => 30])),
            ],
        ];

        foreach ($segments as $name => $rules) {
            $segment = Segment::query()->firstOrCreate(
                ['organization_id' => $org->id, 'name' => $name],
                ['rules' => $rules, 'is_published' => true],
            );

            SyncSegmentMembership::dispatchSync($segment->id);
        }

        Segment::query()->firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'High rate (draft)'],
            [
                'rules' => [],
                'draft_rules' => [$rule('Hourly rate above 120', $condition(SegmentConditionType::CustomField, $hourlyRate->key, SegmentOperator::GreaterThan, ['value' => 120]))],
                'is_published' => false,
            ],
        );
    }

    /** @return array<string, Company> */
    private function seedCompanies(Organization $org, User $owner): array
    {
        $enterprise = CompanyType::firstOrCreate(['organization_id' => $org->id, 'name' => 'Enterprise']);
        $sme = CompanyType::firstOrCreate(['organization_id' => $org->id, 'name' => 'SME']);
        $startup = CompanyType::firstOrCreate(['organization_id' => $org->id, 'name' => 'Startup']);
        $agency = CompanyType::firstOrCreate(['organization_id' => $org->id, 'name' => 'Agency']);

        $this->companyField($org, 'VAT Number', 'text', null, 1, unique: true);
        $this->companyField($org, 'Funding Stage', 'select', [
            ['label' => 'Pre-seed'],
            ['label' => 'Seed'],
            ['label' => 'Series A'],
            ['label' => 'Series B'],
        ], 2);
        $this->companyField($org, 'Founded', 'date', null, 3);
        $this->companyField($org, 'Specialties', 'multiselect', [
            ['label' => 'Web'],
            ['label' => 'Mobile'],
            ['label' => 'Design'],
            ['label' => 'DevOps'],
        ], 4);

        $companiesData = [
            ['name' => 'TechCorp Solutions', 'industry' => CompanyIndustry::Software, 'employees' => 1200, 'annual_revenue' => 180000000, 'phone' => '+33 1 23 45 67 89', 'website' => 'https://techcorp.io', 'type' => $enterprise, 'address' => ['France', 'Paris', '12 Rue de Rivoli', '75001'], 'cf' => ['vat_number' => 'FR40303265045'], 'notes' => 'Key account since 2023.'],
            ['name' => 'Cloudbase Nordic', 'industry' => CompanyIndustry::CloudHosting, 'employees' => 85, 'phone' => '+46 8 123 456 78', 'website' => 'https://cloudbase.dev', 'type' => $sme, 'address' => ['Sweden', 'Stockholm', 'Drottninggatan 45', '111 21'], 'cf' => []],
            ['name' => 'HexaSys', 'industry' => CompanyIndustry::Software, 'employees' => 140, 'type' => $sme, 'address' => ['France', 'Lyon', '8 Quai Saint-Antoine', '69002'], 'cf' => []],
            ['name' => 'SecurePeak Ltd', 'industry' => CompanyIndustry::Cybersecurity, 'employees' => 950, 'website' => 'https://securepeak.com', 'type' => $enterprise, 'address' => ['United Kingdom', 'London', '30 St Mary Axe', 'EC3A 8BF'], 'cf' => ['vat_number' => 'GB123456789']],
            ['name' => 'DevStudio Berlin', 'website' => 'https://devstudio.de', 'type' => $agency, 'address' => ['Germany', 'Berlin', 'Torstraße 110', '10119'], 'cf' => ['specialties' => ['Web', 'Design']]],
            ['name' => 'InnoTech SA', 'industry' => CompanyIndustry::FinanceBanking, 'employees' => 2300, 'type' => $enterprise, 'address' => ['Senegal', 'Dakar', 'Avenue Léopold Sédar Senghor', '10200'], 'cf' => ['vat_number' => 'SN0045821'], 'notes' => 'Azure migration opportunity, decision expected Q2.'],
            ['name' => 'StackOps', 'phone' => '+353 1 234 5678', 'website' => 'https://stackops.io', 'type' => $startup, 'address' => ['Ireland', 'Dublin', '1 Grand Canal Square', 'D02 P820'], 'cf' => ['funding_stage' => 'Series A', 'founded' => '2021-03-01']],
            ['name' => 'SoftBridge Europe', 'industry' => CompanyIndustry::Consulting, 'employees' => 60, 'type' => $sme, 'address' => ['Bulgaria', 'Sofia', 'Vitosha Blvd 89', '1463'], 'cf' => []],
            ['name' => 'CyberNode Africa', 'type' => $startup, 'address' => ['Nigeria', 'Lagos', '14 Admiralty Way', '106104'], 'cf' => ['funding_stage' => 'Seed', 'founded' => '2023-01-15']],
            ['name' => 'PixelCraft Studio', 'website' => 'https://pixelcraft.studio', 'type' => $agency, 'address' => ['Spain', 'Barcelona', 'Carrer de Pallars 193', '08005'], 'cf' => ['specialties' => ['Design', 'Mobile']]],
            ['name' => 'DataLab Portugal', 'type' => $startup, 'address' => ['Portugal', 'Lisbon', 'Rua Augusta 27', '1100-048'], 'cf' => ['funding_stage' => 'Pre-seed', 'founded' => '2024-06-01']],
            ['name' => 'CloudShift MENA', 'industry' => CompanyIndustry::CloudHosting, 'employees' => 700, 'type' => $enterprise, 'address' => ['United Arab Emirates', 'Dubai', 'Sheikh Zayed Road', null], 'cf' => ['vat_number' => 'AE100234567800003']],
            ['name' => 'Nordic Security AB', 'industry' => CompanyIndustry::Cybersecurity, 'employees' => 45, 'type' => $sme, 'address' => ['Sweden', 'Stockholm', 'Kungsgatan 12', '111 43'], 'cf' => []],
            ['name' => 'WebScale Paris', 'type' => $agency, 'address' => ['France', 'Paris', '55 Rue du Faubourg Saint-Honoré', '75008'], 'cf' => ['specialties' => ['Web', 'DevOps']]],
        ];

        $companies = [];

        foreach ($companiesData as $data) {
            $company = Company::firstWhere(['organization_id' => $org->id, 'name' => $data['name']]);

            if (! $company) {
                [$country, $city, $street, $zip] = $data['address'];

                $address = Address::create([
                    'organization_id' => $org->id,
                    'country' => $country,
                    'city' => $city,
                    'street' => $street,
                    'zip' => $zip,
                ]);

                $company = Company::create([
                    'organization_id' => $org->id,
                    'company_type_id' => $data['type']->id,
                    'address_id' => $address->id,
                    'name' => $data['name'],
                    'website' => $data['website'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'industry' => $data['industry'] ?? null,
                    'employees' => $data['employees'] ?? null,
                    'annual_revenue' => $data['annual_revenue'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'owner_id' => $data['name'] === 'InnoTech SA' ? null : $owner->id,
                    'custom_field_values' => $this->keyCompanyValues($org, $data['cf']),
                ]);
            }

            $companies[$data['name']] = $company;
        }

        return $companies;
    }

    /**
     * @param  array<string, mixed>  $values  values keyed by the slugified field name; select options are given by label
     * @return array<string, mixed> values keyed by the fields' generated keys
     *
     * @throws RuntimeException when a value has no custom field of that name on the company type
     */
    private function keyCompanyValues(Organization $organization, array $values): array
    {
        $fieldsBySlug = CompanyCustomField::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->keyBy(fn (CompanyCustomField $field): string => Str::slug($field->name, '_'));

        return collect($values)
            ->mapWithKeys(function (mixed $value, string $slug) use ($fieldsBySlug): array {
                $field = $fieldsBySlug->get($slug);

                if ($field === null) {
                    throw new RuntimeException("The seeder has a value for [{$slug}], but the organization has no company field with that name.");
                }

                $value = match ($field->type) {
                    'select' => $this->optionValue($field, $value),
                    'multiselect' => $this->optionValues($field, $value),
                    default => $value,
                };

                return [$field->key => $value];
            })
            ->all();
    }

    private function companyField(Organization $organization, string $name, string $fieldType, ?array $options, int $order, bool $unique = false): CompanyCustomField
    {
        return CompanyCustomField::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => $name],
            [
                'type' => $fieldType,
                'options' => $options,
                'unique' => $unique,
                'order' => $order,
            ]
        );
    }

    private function field(int $orgId, string $name, string $type, ?array $options, int $order): CustomField
    {
        return CustomField::firstOrCreate(
            ['organization_id' => $orgId, 'name' => $name],
            [
                'type' => $type,
                'options' => $options,
                'unique' => false,
                'order' => $order,
            ]
        );
    }

    /**
     * Options are declared by label and get their stored values from the model, like in the app: the seed refers to
     * them by label.
     *
     * @param  array<int, string>  $labels
     * @return array<int, string>
     *
     * @throws RuntimeException when a label is not an option of the field
     */
    private function optionValues(CustomField|CompanyCustomField $field, array $labels): array
    {
        $valuesByLabel = collect($field->options ?? [])->pluck('value', 'label');

        return array_map(function (string $label) use ($field, $valuesByLabel): string {
            if (! $valuesByLabel->has($label)) {
                throw new RuntimeException("The seeder refers to the option [{$label}], but custom field [{$field->name}] has no option with that label.");
            }

            return $valuesByLabel[$label];
        }, $labels);
    }

    private function optionValue(CustomField|CompanyCustomField $field, string $label): string
    {
        return $this->optionValues($field, [$label])[0];
    }
}
