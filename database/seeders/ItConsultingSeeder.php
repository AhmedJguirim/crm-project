<?php

namespace Database\Seeders;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Activity;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
            ['label' => 'Retainer',         'value' => 'retainer'],
            ['label' => 'Project-Based',    'value' => 'project'],
            ['label' => 'Time & Materials', 'value' => 'time_materials'],
            ['label' => 'Fixed Price',      'value' => 'fixed_price'],
        ], 3);
        $techStack = $this->field($org->id, 'Tech Stack', 'multiselect', [
            ['label' => 'PHP / Laravel',        'value' => 'laravel'],
            ['label' => 'JavaScript / Node.js', 'value' => 'nodejs'],
            ['label' => 'React',                'value' => 'react'],
            ['label' => 'Vue.js',               'value' => 'vue'],
            ['label' => 'Python',               'value' => 'python'],
            ['label' => 'AWS',                  'value' => 'aws'],
            ['label' => 'Azure',                'value' => 'azure'],
            ['label' => 'Docker / Kubernetes',  'value' => 'docker'],
            ['label' => 'PostgreSQL',           'value' => 'postgresql'],
        ], 4);
        $hourlyRate = $this->field($org->id, 'Hourly Rate (€)', 'number', null, 5);
        $contractStart = $this->field($org->id, 'Contract Start', 'date', null, 6);
        $contractEnd = $this->field($org->id, 'Contract End', 'date', null, 7);
        $ndaStatus = $this->field($org->id, 'NDA Status', 'select', [
            ['label' => 'Signed',       'value' => 'signed'],
            ['label' => 'Pending',      'value' => 'pending'],
            ['label' => 'Not Required', 'value' => 'not_required'],
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
                    $contractType->key => 'retainer',
                    $techStack->key => ['laravel', 'aws', 'docker'],
                    $hourlyRate->key => 150,
                    $contractStart->key => '2025-01-15',
                    $contractEnd->key => '2025-12-31',
                    $ndaStatus->key => 'signed',
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
                    $contractType->key => 'time_materials',
                    $techStack->key => ['aws', 'docker', 'python'],
                    $hourlyRate->key => 135,
                    $contractStart->key => '2024-09-01',
                    $contractEnd->key => '2026-03-31',
                    $ndaStatus->key => 'signed',
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
                    $contractType->key => 'project',
                    $techStack->key => ['react', 'nodejs', 'postgresql'],
                    $hourlyRate->key => 120,
                    $ndaStatus->key => 'pending',
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
                    $contractType->key => 'retainer',
                    $techStack->key => ['aws', 'azure'],
                    $hourlyRate->key => 200,
                    $contractStart->key => '2024-03-01',
                    $contractEnd->key => '2026-02-28',
                    $ndaStatus->key => 'signed',
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
                    $contractType->key => 'fixed_price',
                    $techStack->key => ['vue', 'laravel', 'postgresql'],
                    $hourlyRate->key => 110,
                    $ndaStatus->key => 'not_required',
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
                    $contractType->key => 'project',
                    $techStack->key => ['azure', 'python'],
                    $ndaStatus->key => 'pending',
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
                    $contractType->key => 'time_materials',
                    $techStack->key => ['docker', 'aws', 'python'],
                    $hourlyRate->key => 130,
                    $contractStart->key => '2025-03-01',
                    $ndaStatus->key => 'signed',
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
                    $contractType->key => 'project',
                    $techStack->key => ['laravel', 'postgresql', 'react'],
                    $hourlyRate->key => 95,
                    $ndaStatus->key => 'not_required',
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
                    $contractType->key => 'project',
                    $techStack->key => ['nodejs', 'react'],
                    $ndaStatus->key => 'not_required',
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
                    $contractType->key => 'project',
                    $techStack->key => ['vue', 'react'],
                    $ndaStatus->key => 'signed',
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
                    $contractType->key => 'time_materials',
                    $techStack->key => ['python', 'postgresql', 'aws'],
                    $hourlyRate->key => 100,
                    $ndaStatus->key => 'pending',
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
                    $contractType->key => 'retainer',
                    $techStack->key => ['azure', 'docker'],
                    $hourlyRate->key => 175,
                    $ndaStatus->key => 'pending',
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
                    $contractType->key => 'time_materials',
                    $techStack->key => ['docker', 'aws', 'python'],
                    $hourlyRate->key => 115,
                    $ndaStatus->key => 'signed',
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
                    $contractType->key => 'project',
                    $techStack->key => ['python', 'aws'],
                    $hourlyRate->key => 145,
                    $contractStart->key => '2025-06-01',
                    $contractEnd->key => '2025-11-30',
                    $ndaStatus->key => 'signed',
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
                    $ndaStatus->key => 'not_required',
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
                'custom_field_values' => $data['cf'],
            ]);

            $tagIds = collect($data['tags'])
                ->map(fn (string $name) => $tags[$name]->id)
                ->all();

            $contact->tags()->sync($tagIds);
        }

        // ── Companies ────────────────────────────────────────────────────────
        $companies = $this->seedCompanies($org);

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
            ['title' => 'TechCorp Infra Expansion', 'email' => 'marcus.chen@techcorp.io', 'stage' => DealStage::Negotiating, 'status' => DealStatus::Open, 'value' => 85000, 'currency' => 'USD', 'expected_close_date' => now()->addDays(9)->toDateString(), 'notes' => 'Final legal review before signature.'],
            ['title' => 'Cloudbase Cost Optimization Retainer', 'email' => 'sarah.johansson@cloudbase.dev', 'stage' => DealStage::ProposalSent, 'status' => DealStatus::Open, 'value' => 42000, 'currency' => 'EUR', 'expected_close_date' => now()->addDays(5)->toDateString(), 'notes' => 'Proposal sent, waiting for finance sign-off.'],
            ['title' => 'HexaSys React Platform Migration', 'email' => 'damien.leroy@hexasys.fr', 'stage' => DealStage::Discovery, 'status' => DealStatus::Open, 'value' => 65000, 'currency' => 'EUR', 'expected_close_date' => now()->addDays(18)->toDateString(), 'notes' => 'Technical workshops scheduled next week.'],
            ['title' => 'SecurePeak Annual Security Program', 'email' => 'priya.nair@securepeak.com', 'stage' => DealStage::Won, 'status' => DealStatus::Won, 'value' => 120000, 'currency' => 'USD', 'expected_close_date' => now()->subDays(12)->toDateString(), 'won_at' => now()->subDays(10), 'notes' => 'Contract signed and onboarded.'],
            ['title' => 'InnoTech Transformation Advisory', 'email' => 'amara.diallo@innotech-sa.com', 'stage' => DealStage::Lead, 'status' => DealStatus::Open, 'value' => 30000, 'currency' => 'EUR', 'expected_close_date' => now()->addDays(24)->toDateString(), 'notes' => 'Initial qualification in progress.'],
            ['title' => 'NordicSec Pen Testing Extension', 'email' => 'ingrid.svensson@nordicsec.com', 'stage' => DealStage::Lost, 'status' => DealStatus::Lost, 'value' => 38000, 'currency' => 'USD', 'expected_close_date' => now()->subDays(20)->toDateString(), 'lost_at' => now()->subDays(18), 'notes' => 'Budget reallocated to internal team.'],
        ];

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
                    'stage' => $dealData['stage'],
                    'status' => $dealData['status'],
                    'value' => $dealData['value'],
                    'currency' => $dealData['currency'],
                    'expected_close_date' => $dealData['expected_close_date'],
                    'notes' => $dealData['notes'],
                    'won_at' => $dealData['won_at'] ?? null,
                    'lost_at' => $dealData['lost_at'] ?? null,
                    'created_by' => $user->id,
                ]
            );
        }

        // ── Invoices ─────────────────────────────────────────────────────────
        $invoicesData = [
            ['invoice_number' => 'INV-2026-001', 'email' => 'marcus.chen@techcorp.io', 'deal_title' => 'TechCorp Infra Expansion', 'amount' => 24000, 'currency' => 'USD', 'status' => InvoiceStatus::Paid, 'issued_at' => now()->subDays(20)->toDateString(), 'due_at' => now()->subDays(5)->toDateString(), 'paid_at' => now()->subDays(6)->toDateString(), 'notes' => 'Phase 1 infrastructure migration milestone.'],
            ['invoice_number' => 'INV-2026-002', 'email' => 'sarah.johansson@cloudbase.dev', 'deal_title' => 'Cloudbase Cost Optimization Retainer', 'amount' => 7800, 'currency' => 'EUR', 'status' => InvoiceStatus::Sent, 'issued_at' => now()->subDays(9)->toDateString(), 'due_at' => now()->addDays(7)->toDateString(), 'notes' => 'Monthly retainer February.'],
            ['invoice_number' => 'INV-2026-003', 'email' => 'damien.leroy@hexasys.fr', 'deal_title' => 'HexaSys React Platform Migration', 'amount' => 5600, 'currency' => 'EUR', 'status' => InvoiceStatus::Partial, 'issued_at' => now()->subDays(18)->toDateString(), 'due_at' => now()->subDays(2)->toDateString(), 'paid_at' => now()->subDays(1)->toDateString(), 'notes' => 'Deposit received, awaiting final transfer.'],
            ['invoice_number' => 'INV-2026-004', 'email' => 'ingrid.svensson@nordicsec.com', 'deal_title' => 'NordicSec Pen Testing Extension', 'amount' => 9200, 'currency' => 'USD', 'status' => InvoiceStatus::Overdue, 'issued_at' => now()->subDays(26)->toDateString(), 'due_at' => now()->subDays(8)->toDateString(), 'notes' => 'Reminder sent twice.'],
            ['invoice_number' => 'INV-2026-005', 'email' => 'priya.nair@securepeak.com', 'deal_title' => 'SecurePeak Annual Security Program', 'amount' => 31000, 'currency' => 'USD', 'status' => InvoiceStatus::Paid, 'issued_at' => now()->subDays(40)->toDateString(), 'due_at' => now()->subDays(25)->toDateString(), 'paid_at' => now()->subDays(24)->toDateString(), 'notes' => 'Annual security program Q1 billing.'],
            ['invoice_number' => 'INV-2026-006', 'email' => 'amara.diallo@innotech-sa.com', 'deal_title' => null, 'amount' => 3500, 'currency' => 'EUR', 'status' => InvoiceStatus::Draft, 'issued_at' => now()->toDateString(), 'due_at' => now()->addDays(14)->toDateString(), 'notes' => 'Draft invoice for discovery workshops.'],
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

            Invoice::firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'invoice_number' => $invoiceData['invoice_number'],
                ],
                [
                    'contact_id' => $contact->id,
                    'deal_id' => $deal?->id,
                    'amount' => $invoiceData['amount'],
                    'currency' => $invoiceData['currency'],
                    'status' => $invoiceData['status'],
                    'issued_at' => $invoiceData['issued_at'],
                    'due_at' => $invoiceData['due_at'],
                    'paid_at' => $invoiceData['paid_at'] ?? null,
                    'notes' => $invoiceData['notes'] ?? null,
                ]
            );
        }

        // ── Tasks ────────────────────────────────────────────────────────────
        $tasksData = [
            ['title' => 'Finalize TechCorp contract redlines', 'email' => 'marcus.chen@techcorp.io', 'type' => TaskType::FollowUp, 'priority' => TaskPriority::High, 'status' => TaskStatus::Pending, 'due_at' => now()->addDay(), 'notes' => 'Review legal comments and send final draft.'],
            ['title' => 'Call Sarah for proposal approval', 'email' => 'sarah.johansson@cloudbase.dev', 'type' => TaskType::Call, 'priority' => TaskPriority::Medium, 'status' => TaskStatus::Pending, 'due_at' => now()->addDays(2), 'notes' => 'Confirm timeline and procurement process.'],
            ['title' => 'Prepare HexaSys discovery workshop agenda', 'email' => 'damien.leroy@hexasys.fr', 'type' => TaskType::Meeting, 'priority' => TaskPriority::High, 'status' => TaskStatus::Pending, 'due_at' => now()->addDays(3), 'notes' => 'Share architecture questionnaire in advance.'],
            ['title' => 'Send SecurePeak kickoff summary', 'email' => 'priya.nair@securepeak.com', 'type' => TaskType::Email, 'priority' => TaskPriority::Low, 'status' => TaskStatus::Done, 'due_at' => now()->subDays(2), 'completed_at' => now()->subDay(), 'notes' => 'Kickoff notes and next milestones sent.'],
            ['title' => 'Invoice StackOps sprint support', 'email' => 'tom.whitfield@stackops.io', 'type' => TaskType::Invoice, 'priority' => TaskPriority::Medium, 'status' => TaskStatus::Pending, 'due_at' => now()->addDays(5), 'notes' => 'Prepare invoice for February sprint support.'],
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
                    'type' => $taskData['type'],
                    'priority' => $taskData['priority'],
                    'status' => $taskData['status'],
                    'due_at' => $taskData['due_at'],
                    'completed_at' => $taskData['completed_at'] ?? null,
                    'notes' => $taskData['notes'] ?? null,
                ]
            );
        }
    }

    /** @return array<string, Company> */
    private function seedCompanies(Organization $org): array
    {
        $enterprise = CompanyType::firstOrCreate(['organization_id' => $org->id, 'name' => 'Enterprise']);
        $sme = CompanyType::firstOrCreate(['organization_id' => $org->id, 'name' => 'SME']);
        $startup = CompanyType::firstOrCreate(['organization_id' => $org->id, 'name' => 'Startup']);
        $agency = CompanyType::firstOrCreate(['organization_id' => $org->id, 'name' => 'Agency']);

        $industryOptions = [
            ['label' => 'Software',          'value' => 'software'],
            ['label' => 'Finance',           'value' => 'finance'],
            ['label' => 'Cybersecurity',     'value' => 'cybersecurity'],
            ['label' => 'Cloud & Hosting',   'value' => 'cloud'],
            ['label' => 'Consulting',        'value' => 'consulting'],
        ];

        foreach ([$enterprise, $sme] as $type) {
            $this->companyField($type, 'Industry', 'select', $industryOptions, 1);
            $this->companyField($type, 'Employees', 'number', null, 2);
            $this->companyField($type, 'Website', 'url', null, 3);
        }

        $this->companyField($enterprise, 'VAT Number', 'text', null, 4, unique: true);
        $this->companyField($enterprise, 'Annual Revenue (€)', 'number', null, 5);

        $this->companyField($startup, 'Funding Stage', 'select', [
            ['label' => 'Pre-seed', 'value' => 'pre_seed'],
            ['label' => 'Seed',     'value' => 'seed'],
            ['label' => 'Series A', 'value' => 'series_a'],
            ['label' => 'Series B', 'value' => 'series_b'],
        ], 1);
        $this->companyField($startup, 'Founded', 'date', null, 2);
        $this->companyField($startup, 'Website', 'url', null, 3);

        $this->companyField($agency, 'Specialties', 'multiselect', [
            ['label' => 'Web',     'value' => 'web'],
            ['label' => 'Mobile',  'value' => 'mobile'],
            ['label' => 'Design',  'value' => 'design'],
            ['label' => 'DevOps',  'value' => 'devops'],
        ], 1);
        $this->companyField($agency, 'Website', 'url', null, 2);

        $companiesData = [
            ['name' => 'TechCorp Solutions', 'type' => $enterprise, 'address' => ['France', 'Paris', '12 Rue de Rivoli', '75001'], 'cf' => ['industry' => 'software', 'employees' => 1200, 'website' => 'https://techcorp.io', 'vat_number' => 'FR40303265045', 'annual_revenue_eur' => 180000000], 'notes' => 'Key account since 2023.'],
            ['name' => 'Cloudbase Nordic', 'type' => $sme, 'address' => ['Sweden', 'Stockholm', 'Drottninggatan 45', '111 21'], 'cf' => ['industry' => 'cloud', 'employees' => 85, 'website' => 'https://cloudbase.dev']],
            ['name' => 'HexaSys', 'type' => $sme, 'address' => ['France', 'Lyon', '8 Quai Saint-Antoine', '69002'], 'cf' => ['industry' => 'software', 'employees' => 140]],
            ['name' => 'SecurePeak Ltd', 'type' => $enterprise, 'address' => ['United Kingdom', 'London', '30 St Mary Axe', 'EC3A 8BF'], 'cf' => ['industry' => 'cybersecurity', 'employees' => 950, 'website' => 'https://securepeak.com', 'vat_number' => 'GB123456789']],
            ['name' => 'DevStudio Berlin', 'type' => $agency, 'address' => ['Germany', 'Berlin', 'Torstraße 110', '10119'], 'cf' => ['specialties' => ['web', 'design'], 'website' => 'https://devstudio.de']],
            ['name' => 'InnoTech SA', 'type' => $enterprise, 'address' => ['Senegal', 'Dakar', 'Avenue Léopold Sédar Senghor', '10200'], 'cf' => ['industry' => 'finance', 'employees' => 2300, 'vat_number' => 'SN0045821'], 'notes' => 'Azure migration opportunity, decision expected Q2.'],
            ['name' => 'StackOps', 'type' => $startup, 'address' => ['Ireland', 'Dublin', '1 Grand Canal Square', 'D02 P820'], 'cf' => ['funding_stage' => 'series_a', 'founded' => '2021-03-01', 'website' => 'https://stackops.io']],
            ['name' => 'SoftBridge Europe', 'type' => $sme, 'address' => ['Bulgaria', 'Sofia', 'Vitosha Blvd 89', '1463'], 'cf' => ['industry' => 'consulting', 'employees' => 60]],
            ['name' => 'CyberNode Africa', 'type' => $startup, 'address' => ['Nigeria', 'Lagos', '14 Admiralty Way', '106104'], 'cf' => ['funding_stage' => 'seed', 'founded' => '2023-01-15']],
            ['name' => 'PixelCraft Studio', 'type' => $agency, 'address' => ['Spain', 'Barcelona', 'Carrer de Pallars 193', '08005'], 'cf' => ['specialties' => ['design', 'mobile'], 'website' => 'https://pixelcraft.studio']],
            ['name' => 'DataLab Portugal', 'type' => $startup, 'address' => ['Portugal', 'Lisbon', 'Rua Augusta 27', '1100-048'], 'cf' => ['funding_stage' => 'pre_seed', 'founded' => '2024-06-01']],
            ['name' => 'CloudShift MENA', 'type' => $enterprise, 'address' => ['United Arab Emirates', 'Dubai', 'Sheikh Zayed Road', null], 'cf' => ['industry' => 'cloud', 'employees' => 700, 'vat_number' => 'AE100234567800003']],
            ['name' => 'Nordic Security AB', 'type' => $sme, 'address' => ['Sweden', 'Stockholm', 'Kungsgatan 12', '111 43'], 'cf' => ['industry' => 'cybersecurity', 'employees' => 45]],
            ['name' => 'WebScale Paris', 'type' => $agency, 'address' => ['France', 'Paris', '55 Rue du Faubourg Saint-Honoré', '75008'], 'cf' => ['specialties' => ['web', 'devops']]],
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
                    'notes' => $data['notes'] ?? null,
                    'custom_field_values' => $this->keyCompanyValues($data['type'], $data['cf']),
                ]);
            }

            $companies[$data['name']] = $company;
        }

        return $companies;
    }

    /**
     * @param  array<string, mixed>  $values  values keyed by the slugified field name
     * @return array<string, mixed> values keyed by the fields' generated keys
     */
    private function keyCompanyValues(CompanyType $type, array $values): array
    {
        $keysBySlug = CompanyCustomField::query()
            ->where('company_type_id', $type->id)
            ->get()
            ->mapWithKeys(fn (CompanyCustomField $field): array => [Str::slug($field->name, '_') => $field->key]);

        return collect($values)
            ->mapWithKeys(fn (mixed $value, string $slug): array => [$keysBySlug[$slug] => $value])
            ->all();
    }

    private function companyField(CompanyType $type, string $name, string $fieldType, ?array $options, int $order, bool $unique = false): CompanyCustomField
    {
        return CompanyCustomField::firstOrCreate(
            ['company_type_id' => $type->id, 'name' => $name],
            [
                'organization_id' => $type->organization_id,
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
}
