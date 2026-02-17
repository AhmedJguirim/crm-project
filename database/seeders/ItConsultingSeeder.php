<?php

namespace Database\Seeders;

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
