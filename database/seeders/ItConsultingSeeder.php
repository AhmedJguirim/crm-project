<?php

namespace Database\Seeders;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
        $company = $this->field($org->id, 'Company', 'text', null, 1);
        $jobTitle = $this->field($org->id, 'Job Title', 'text', null, 2);
        $linkedin = $this->field($org->id, 'LinkedIn', 'url', null, 3);
        $contractType = $this->field($org->id, 'Contract Type', 'select', [
            ['label' => 'Retainer',         'value' => 'retainer'],
            ['label' => 'Project-Based',    'value' => 'project'],
            ['label' => 'Time & Materials', 'value' => 'time_materials'],
            ['label' => 'Fixed Price',      'value' => 'fixed_price'],
        ], 4);
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
        ], 5);
        $hourlyRate = $this->field($org->id, 'Hourly Rate (€)', 'number', null, 6);
        $contractStart = $this->field($org->id, 'Contract Start', 'date', null, 7);
        $contractEnd = $this->field($org->id, 'Contract End', 'date', null, 8);
        $ndaStatus = $this->field($org->id, 'NDA Status', 'select', [
            ['label' => 'Signed',       'value' => 'signed'],
            ['label' => 'Pending',      'value' => 'pending'],
            ['label' => 'Not Required', 'value' => 'not_required'],
        ], 9);
        $notes = $this->field($org->id, 'Internal Notes', 'textarea', null, 10);

        // ── Contacts ──────────────────────────────────────────────────────────
        $contactsData = [
            [
                'name' => 'Marcus Chen',
                'email' => 'marcus.chen@techcorp.io',
                'phone' => '+33 6 12 34 56 78',
                'tags' => ['Client', 'VIP', 'Technical'],
                'cf' => [
                    $company->id => 'TechCorp Solutions',
                    $jobTitle->id => 'CTO',
                    $linkedin->id => 'https://linkedin.com/in/marcuschen',
                    $contractType->id => 'retainer',
                    $techStack->id => ['laravel', 'aws', 'docker'],
                    $hourlyRate->id => 150,
                    $contractStart->id => '2025-01-15',
                    $contractEnd->id => '2025-12-31',
                    $ndaStatus->id => 'signed',
                    $notes->id => 'Key account. Prefers morning calls. Annual review in December.',
                ],
            ],
            [
                'name' => 'Sarah Johansson',
                'email' => 'sarah.johansson@cloudbase.dev',
                'phone' => '+46 70 234 5678',
                'tags' => ['Client', 'DevOps'],
                'cf' => [
                    $company->id => 'Cloudbase Nordic',
                    $jobTitle->id => 'Head of Infrastructure',
                    $linkedin->id => 'https://linkedin.com/in/sarahjohansson',
                    $contractType->id => 'time_materials',
                    $techStack->id => ['aws', 'docker', 'python'],
                    $hourlyRate->id => 135,
                    $contractStart->id => '2024-09-01',
                    $contractEnd->id => '2026-03-31',
                    $ndaStatus->id => 'signed',
                    $notes->id => 'Monthly invoicing. AWS cost optimisation ongoing.',
                ],
            ],
            [
                'name' => 'Damien Leroy',
                'email' => 'damien.leroy@hexasys.fr',
                'phone' => '+33 1 42 86 57 30',
                'tags' => ['Prospect', 'Management'],
                'cf' => [
                    $company->id => 'HexaSys',
                    $jobTitle->id => 'VP Engineering',
                    $linkedin->id => 'https://linkedin.com/in/damienleroy',
                    $contractType->id => 'project',
                    $techStack->id => ['react', 'nodejs', 'postgresql'],
                    $hourlyRate->id => 120,
                    $ndaStatus->id => 'pending',
                    $notes->id => 'Intro call done. Awaiting NDA before sending proposal.',
                ],
            ],
            [
                'name' => 'Priya Nair',
                'email' => 'priya.nair@securepeak.com',
                'phone' => '+44 7700 900 123',
                'tags' => ['Client', 'Security', 'VIP'],
                'cf' => [
                    $company->id => 'SecurePeak Ltd',
                    $jobTitle->id => 'CISO',
                    $linkedin->id => 'https://linkedin.com/in/priyanair',
                    $contractType->id => 'retainer',
                    $techStack->id => ['aws', 'azure'],
                    $hourlyRate->id => 200,
                    $contractStart->id => '2024-03-01',
                    $contractEnd->id => '2026-02-28',
                    $ndaStatus->id => 'signed',
                    $notes->id => 'Security audit retainer. Quarterly report deliverable.',
                ],
            ],
            [
                'name' => 'Lars Müller',
                'email' => 'lars.muller@devstudio.de',
                'phone' => '+49 30 1234 5678',
                'tags' => ['Lead', 'Technical'],
                'cf' => [
                    $company->id => 'DevStudio Berlin',
                    $jobTitle->id => 'Lead Developer',
                    $contractType->id => 'fixed_price',
                    $techStack->id => ['vue', 'laravel', 'postgresql'],
                    $hourlyRate->id => 110,
                    $ndaStatus->id => 'not_required',
                    $notes->id => 'Referred by Marcus Chen. Looking for a long-term partner.',
                ],
            ],
            [
                'name' => 'Amara Diallo',
                'email' => 'amara.diallo@innotech-sa.com',
                'phone' => '+41 76 543 2100',
                'tags' => ['Prospect', 'Management'],
                'cf' => [
                    $company->id => 'InnoTech SA',
                    $jobTitle->id => 'Digital Transformation Director',
                    $linkedin->id => 'https://linkedin.com/in/amaradiallo',
                    $contractType->id => 'project',
                    $techStack->id => ['azure', 'python'],
                    $ndaStatus->id => 'pending',
                    $notes->id => 'Large digital transformation project. Budget TBD.',
                ],
            ],
            [
                'name' => 'Tom Whitfield',
                'email' => 'tom.whitfield@stackops.io',
                'phone' => '+44 20 7946 0958',
                'tags' => ['Client', 'DevOps', 'Technical'],
                'cf' => [
                    $company->id => 'StackOps',
                    $jobTitle->id => 'Platform Engineer',
                    $contractType->id => 'time_materials',
                    $techStack->id => ['docker', 'aws', 'python'],
                    $hourlyRate->id => 130,
                    $contractStart->id => '2025-03-01',
                    $ndaStatus->id => 'signed',
                ],
            ],
            [
                'name' => 'Nadia Petrova',
                'email' => 'nadia.petrova@softbridge.eu',
                'phone' => '+31 6 87654321',
                'tags' => ['Lead', 'Technical'],
                'cf' => [
                    $company->id => 'SoftBridge Europe',
                    $jobTitle->id => 'Senior Backend Developer',
                    $linkedin->id => 'https://linkedin.com/in/nadiapetrova',
                    $contractType->id => 'project',
                    $techStack->id => ['laravel', 'postgresql', 'react'],
                    $hourlyRate->id => 95,
                    $ndaStatus->id => 'not_required',
                    $notes->id => 'Interested in a 3-month Laravel API project.',
                ],
            ],
            [
                'name' => 'James Okafor',
                'email' => 'james.okafor@cybernode.ng',
                'phone' => '+234 802 345 6789',
                'tags' => ['Cold'],
                'cf' => [
                    $company->id => 'CyberNode Africa',
                    $jobTitle->id => 'Product Manager',
                    $contractType->id => 'project',
                    $techStack->id => ['nodejs', 'react'],
                    $ndaStatus->id => 'not_required',
                    $notes->id => 'Initial contact at WebSummit. Follow up Q2 2026.',
                ],
            ],
            [
                'name' => 'Elena Vasquez',
                'email' => 'elena.vasquez@pixelcraft.studio',
                'phone' => '+34 612 345 678',
                'tags' => ['Partner'],
                'cf' => [
                    $company->id => 'PixelCraft Studio',
                    $jobTitle->id => 'Design Director',
                    $linkedin->id => 'https://linkedin.com/in/elenavasquez',
                    $contractType->id => 'project',
                    $techStack->id => ['vue', 'react'],
                    $ndaStatus->id => 'signed',
                    $notes->id => 'Subcontractor for front-end. Strong Vue.js skills.',
                ],
            ],
            [
                'name' => 'Hugo Fernandes',
                'email' => 'hugo.fernandes@datalab.pt',
                'phone' => '+351 91 234 5678',
                'tags' => ['Prospect', 'Technical'],
                'cf' => [
                    $company->id => 'DataLab Portugal',
                    $jobTitle->id => 'Data Engineer',
                    $contractType->id => 'time_materials',
                    $techStack->id => ['python', 'postgresql', 'aws'],
                    $hourlyRate->id => 100,
                    $ndaStatus->id => 'pending',
                ],
            ],
            [
                'name' => 'Yasmin Al-Rashid',
                'email' => 'yasmin.alrashid@cloudshift.ae',
                'phone' => '+971 50 123 4567',
                'tags' => ['Lead', 'Management', 'VIP'],
                'cf' => [
                    $company->id => 'CloudShift MENA',
                    $jobTitle->id => 'IT Director',
                    $linkedin->id => 'https://linkedin.com/in/yasminrashid',
                    $contractType->id => 'retainer',
                    $techStack->id => ['azure', 'docker'],
                    $hourlyRate->id => 175,
                    $ndaStatus->id => 'pending',
                    $notes->id => 'Referred by Priya Nair. High-value opportunity.',
                ],
            ],
            [
                'name' => 'Florian Dupont',
                'email' => 'florian.dupont@freelance.io',
                'phone' => '+32 475 123 456',
                'tags' => ['Partner', 'DevOps'],
                'cf' => [
                    $company->id => 'Independent',
                    $jobTitle->id => 'DevOps Consultant',
                    $contractType->id => 'time_materials',
                    $techStack->id => ['docker', 'aws', 'python'],
                    $hourlyRate->id => 115,
                    $ndaStatus->id => 'signed',
                    $notes->id => 'Available for overflow work. Max 2 days/week.',
                ],
            ],
            [
                'name' => 'Ingrid Svensson',
                'email' => 'ingrid.svensson@nordicsec.com',
                'phone' => '+46 8 123 456 78',
                'tags' => ['Client', 'Security'],
                'cf' => [
                    $company->id => 'Nordic Security AB',
                    $jobTitle->id => 'Security Analyst',
                    $contractType->id => 'project',
                    $techStack->id => ['python', 'aws'],
                    $hourlyRate->id => 145,
                    $contractStart->id => '2025-06-01',
                    $contractEnd->id => '2025-11-30',
                    $ndaStatus->id => 'signed',
                    $notes->id => 'Pen testing engagement. Deliverable: report + remediation plan.',
                ],
            ],
            [
                'name' => 'Remy Bertrand',
                'email' => 'remy.bertrand@webscale.fr',
                'phone' => null,
                'tags' => ['Cold'],
                'cf' => [
                    $company->id => 'WebScale Paris',
                    $jobTitle->id => 'Engineering Manager',
                    $linkedin->id => 'https://linkedin.com/in/remybertrand',
                    $ndaStatus->id => 'not_required',
                    $notes->id => 'Met at Paris JS conference. No immediate need.',
                ],
            ],
        ];

        foreach ($contactsData as $data) {
            if (Contact::where('organization_id', $org->id)->where('email', $data['email'])->exists()) {
                continue;
            }

            $cfValues = [];
            foreach ($data['cf'] as $fieldId => $value) {
                $cfValues[(string) $fieldId] = $value;
            }

            $contact = Contact::create([
                'organization_id' => $org->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'custom_field_values' => $cfValues,
            ]);

            $tagIds = collect($data['tags'])
                ->map(fn (string $name) => $tags[$name]->id)
                ->all();

            $contact->tags()->sync($tagIds);
        }

        // ── Activities ───────────────────────────────────────────────────────
        $contacts = Contact::where('organization_id', $org->id)->get()->keyBy('email');

        $activitiesData = [
            ['email' => 'marcus.chen@techcorp.io', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-10 09:30:00', 'duration_minutes' => 45, 'subject' => 'Quarterly retainer review', 'notes' => 'Discussed Q1 deliverables and upcoming infrastructure migration. Very satisfied with progress.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'marcus.chen@techcorp.io', 'type' => ActivityType::Email, 'occurred_at' => '2026-02-12 14:15:00', 'subject' => 'Migration timeline proposal', 'notes' => 'Sent updated timeline for AWS migration. Awaiting feedback.'],
            ['email' => 'marcus.chen@techcorp.io', 'type' => ActivityType::Meeting, 'occurred_at' => '2026-01-20 10:00:00', 'duration_minutes' => 90, 'subject' => 'Annual strategy session', 'notes' => 'Full-day planning session for 2026 roadmap. Budget approved for Phase 2.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'sarah.johansson@cloudbase.dev', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-14 11:00:00', 'duration_minutes' => 30, 'subject' => 'AWS cost review', 'notes' => 'Reviewed last month invoices. Found 15% saving opportunity on reserved instances.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'sarah.johansson@cloudbase.dev', 'type' => ActivityType::WhatsApp, 'occurred_at' => '2026-02-16 08:45:00', 'notes' => 'Quick check-in about the staging cluster issue. Resolved overnight.'],
            ['email' => 'damien.leroy@hexasys.fr', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-05 15:00:00', 'duration_minutes' => 25, 'subject' => 'Intro call', 'notes' => 'Discussed their React migration needs. Interested in a 6-month engagement.', 'outcome' => ActivityOutcome::FollowUpNeeded, 'follow_up_at' => '2026-02-24 10:00:00'],
            ['email' => 'damien.leroy@hexasys.fr', 'type' => ActivityType::Email, 'occurred_at' => '2026-02-06 10:30:00', 'subject' => 'NDA sent for signature', 'notes' => 'Sent NDA via DocuSign. Expected turnaround: 2 business days.'],
            ['email' => 'priya.nair@securepeak.com', 'type' => ActivityType::Meeting, 'occurred_at' => '2026-02-03 14:00:00', 'duration_minutes' => 60, 'subject' => 'Q4 2025 security audit report', 'notes' => 'Presented findings. 3 critical vulnerabilities patched, 12 medium resolved.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'priya.nair@securepeak.com', 'type' => ActivityType::Email, 'occurred_at' => '2026-02-10 09:00:00', 'subject' => 'Remediation plan v2', 'notes' => 'Updated plan after feedback. Deadline pushed to March 15.'],
            ['email' => 'lars.muller@devstudio.de', 'type' => ActivityType::InPerson, 'occurred_at' => '2026-01-28 12:00:00', 'duration_minutes' => 75, 'subject' => 'Lunch meeting in Berlin', 'notes' => 'Met at their office. Great cultural fit. They want a Laravel + Vue stack for their new product.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'amara.diallo@innotech-sa.com', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-11 16:00:00', 'duration_minutes' => 40, 'subject' => 'Discovery call', 'notes' => 'Large-scale Azure migration. Budget range 200k-350k EUR. Decision expected Q2.', 'outcome' => ActivityOutcome::FollowUpNeeded, 'follow_up_at' => '2026-03-10 14:00:00'],
            ['email' => 'tom.whitfield@stackops.io', 'type' => ActivityType::WhatsApp, 'occurred_at' => '2026-02-15 17:30:00', 'notes' => 'Confirmed next sprint planning for Monday 10 AM.'],
            ['email' => 'tom.whitfield@stackops.io', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-13 10:00:00', 'duration_minutes' => 20, 'subject' => 'Sprint retrospective', 'notes' => 'Good velocity this sprint. One blocker on CI pipeline resolved.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'nadia.petrova@softbridge.eu', 'type' => ActivityType::Email, 'occurred_at' => '2026-02-07 11:00:00', 'subject' => 'Laravel API project proposal', 'notes' => 'Sent 3-month project proposal with milestones and pricing.', 'outcome' => ActivityOutcome::Neutral],
            ['email' => 'james.okafor@cybernode.ng', 'type' => ActivityType::Note, 'occurred_at' => '2026-01-15 09:00:00', 'subject' => 'WebSummit follow-up reminder', 'notes' => 'Met at WebSummit booth. Expressed interest in a React/Node project. Follow up in Q2 2026.'],
            ['email' => 'elena.vasquez@pixelcraft.studio', 'type' => ActivityType::Call, 'occurred_at' => '2026-02-09 14:00:00', 'duration_minutes' => 15, 'subject' => 'Availability check', 'notes' => 'Available for 3 days/week starting March. Confirmed rate.', 'outcome' => ActivityOutcome::Positive],
            ['email' => 'yasmin.alrashid@cloudshift.ae', 'type' => ActivityType::Meeting, 'occurred_at' => '2026-02-04 11:00:00', 'duration_minutes' => 60, 'subject' => 'Virtual intro meeting', 'notes' => 'Referred by Priya. Looking for a managed cloud services partner. Very promising.', 'outcome' => ActivityOutcome::FollowUpNeeded, 'follow_up_at' => '2026-02-25 11:00:00'],
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
                    'follow_up_at' => $actData['follow_up_at'] ?? null,
                ]
            );
        }
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
