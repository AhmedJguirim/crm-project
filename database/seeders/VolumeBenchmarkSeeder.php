<?php

namespace Database\Seeders;

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Jobs\SyncSegmentMembership;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Deals\DefaultPipeline;
use App\Support\Benchmark\BenchmarkDatabase;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fills a separate benchmark database with one big organization (10,000 contacts, 2,000 companies, 2,000 deals, 20,000
 * activities, 2,000 tasks, 1,000 invoices, the demo tags, fields and segments) to measure page times. It is not part
 * of `DatabaseSeeder` and refuses to run anywhere but on the `srw-crm-bench` database (or the PHPUnit databases) with
 * synchronous queues, so no volume ever lands in the development database.
 *
 * Run it with `php artisan --env=benchmark db:seed --class=VolumeBenchmarkSeeder --no-interaction`.
 */
class VolumeBenchmarkSeeder extends Seeder
{
    public const string DATABASE = BenchmarkDatabase::NAME;

    private const int CHUNK = 1000;

    private const string OWNER_EMAIL = BenchmarkDatabase::OWNER_EMAIL;

    public function run(): void
    {
        $contacts = 10_000;

        $this->seedVolume($contacts);

        $this->command?->info("Benchmark Org seeded: {$contacts} contacts.");
    }

    /**
     * Seeds the benchmark organization with `$contacts` contacts and the volumes derived from it (a fifth of it in
     * companies, deals and tasks, a tenth in invoices, twice as many activities), all in one transaction.
     *
     * @throws RuntimeException When the target database, environment or queue is not the benchmark's, or the benchmark organization already exists.
     */
    public function seedVolume(int $contacts = 10_000): Organization
    {
        $this->ensureSafeTarget();

        if (User::query()->where('email', self::OWNER_EMAIL)->exists()) {
            throw new RuntimeException('The benchmark organization already exists. Run php artisan --env=benchmark migrate:fresh --no-interaction first.');
        }

        return DB::transaction(function () use ($contacts): Organization {
            $owner = $this->createUser('Benchmark Owner', self::OWNER_EMAIL);
            $organization = $owner->createPersonalOrganization();
            $organization->update(['name' => 'Benchmark Org']);

            $admin = $this->createTeammate($organization, 'Benchmark Admin', 'bench-admin@bench.invalid', OrganizationRole::Admin);
            $member = $this->createTeammate($organization, 'Benchmark Member', 'bench-member@bench.invalid', OrganizationRole::Member);

            app(ItConsultingSeeder::class)->seedDemoOrganization($organization, $owner, $admin, $member);

            app(TenantContext::class)->run($organization->id, function () use ($organization, $contacts, $owner, $admin, $member): void {
                $this->seedVolumeRows($organization->id, $contacts, [$owner->id, $admin->id, $member->id]);
                $this->syncSegments($organization->id);
            });

            return $organization;
        });
    }

    /**
     * Refuses unless the app runs `--env=benchmark` on the benchmark database (or the testing environment on a
     * PHPUnit database), on PostgreSQL, with an uncached config and synchronous queues: a Redis queue would hand the
     * segment jobs to the development workers, which run on the development database.
     *
     * @throws RuntimeException
     */
    private function ensureSafeTarget(): void
    {
        if (BenchmarkDatabase::isSafeTarget()) {
            return;
        }

        throw new RuntimeException('Refusing to seed the volume benchmark: it runs only with --env=benchmark on the srw-crm-bench database with sync queues (found '.BenchmarkDatabase::describeTarget().'). Run php artisan config:clear and check .env.benchmark.');
    }

    /**
     * A verified user with a random password nobody knows, on the reserved `.invalid` domain (no mail reaches anyone).
     */
    private function createUser(string $name, string $email): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make(Str::random(64)), 'onboarding_completed' => true]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function createTeammate(Organization $organization, string $name, string $email, OrganizationRole $role): User
    {
        $teammate = $this->createUser($name, $email);

        $organization->members()->attach($teammate->id, ['role' => $role->value]);

        return $teammate;
    }

    /**
     * Companies, contacts, their links, tags and activities go in without model events (their observers only fill the
     * organization and queue a resync, which the final segment sync replaces); deals, tasks and invoices keep their
     * events (stage history, currency rules).
     *
     * @param  array<int, int>  $team  the owner, admin and member ids
     */
    private function seedVolumeRows(int $organizationId, int $contacts, array $team): void
    {
        $tagIds = Tag::query()->where('organization_id', $organizationId)->orderBy('id')->pluck('id')->all();

        $contactIds = Model::withoutEvents(function () use ($organizationId, $contacts, $team, $tagIds): array {
            $companyIds = $this->seedCompanies($organizationId, intdiv($contacts, 5));
            $contactIds = $this->seedContacts($organizationId, $contacts);

            $this->linkCompanies($contactIds, $companyIds);
            $this->tagContacts($contactIds, $tagIds);
            $this->seedActivities($organizationId, $contactIds, $team);

            return $contactIds;
        });

        $this->seedDeals($organizationId, $contactIds, $team, intdiv($contacts, 5));
        $this->seedTasks($organizationId, $contactIds, $team, intdiv($contacts, 5));
        $this->seedInvoices($organizationId, $contactIds, intdiv($contacts, 10));
    }

    /**
     * Creates `$total` rows of a factory in chunks of 1,000 and returns only their ids; `$attributes` gets the 0-based
     * index of the row.
     *
     * @param  Factory<Model>  $factory
     * @param  Closure(int): array<string, mixed>  $attributes
     * @return array<int, int>
     */
    private function createInChunks(Factory $factory, int $total, Closure $attributes): array
    {
        $ids = [];

        for ($offset = 0; $offset < $total; $offset += self::CHUNK) {
            $start = $offset;
            $size = min(self::CHUNK, $total - $offset);

            $created = $factory->count($size)
                ->sequence(fn (Sequence $sequence): array => $attributes($start + $sequence->index))
                ->create();

            array_push($ids, ...$created->modelKeys());
        }

        return $ids;
    }

    /**
     * @return array<int, int> the company ids by index
     */
    private function seedCompanies(int $organizationId, int $total): array
    {
        return $this->createInChunks(Company::factory(), $total, fn (int $c): array => [
            'organization_id' => $organizationId,
            'name' => sprintf('Bench Company %05d', $c),
        ]);
    }

    /**
     * @return array<int, int> the contact ids by index
     */
    private function seedContacts(int $organizationId, int $total): array
    {
        return $this->createInChunks(Contact::factory(), $total, fn (int $i): array => [
            'organization_id' => $organizationId,
            'name' => sprintf('Bench Contact %05d', $i),
            'email' => sprintf('bench.contact.%05d@example.test', $i),
        ]);
    }

    /**
     * Every contact but each fifth belongs to one company.
     *
     * @param  array<int, int>  $contactIds
     * @param  array<int, int>  $companyIds
     */
    private function linkCompanies(array $contactIds, array $companyIds): void
    {
        if ($companyIds === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($contactIds as $i => $contactId) {
            if ($i % 5 === 0) {
                continue;
            }

            $rows[] = ['company_id' => $companyIds[$i % count($companyIds)], 'contact_id' => $contactId, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('company_contact')->insert($chunk);
        }
    }

    /**
     * Every contact gets one demo tag, every third one a second.
     *
     * @param  array<int, int>  $contactIds
     * @param  array<int, int>  $tagIds
     */
    private function tagContacts(array $contactIds, array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }

        $rows = [];

        foreach ($contactIds as $i => $contactId) {
            $rows[] = ['contact_id' => $contactId, 'tag_id' => $tagIds[$i % count($tagIds)]];

            if ($i % 3 === 0) {
                $rows[] = ['contact_id' => $contactId, 'tag_id' => $tagIds[($i + 1) % count($tagIds)]];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('contact_tag')->insert($chunk);
        }
    }

    /**
     * Two activities per contact, spread over the team.
     *
     * @param  array<int, int>  $contactIds
     * @param  array<int, int>  $team
     */
    private function seedActivities(int $organizationId, array $contactIds, array $team): void
    {
        $contacts = count($contactIds);

        $this->createInChunks(Activity::factory(), $contacts * 2, fn (int $k): array => [
            'organization_id' => $organizationId,
            'contact_id' => $contactIds[$k % $contacts],
            'user_id' => $team[$k % 3],
            'subject' => sprintf('Bench activity %05d', $k),
        ]);
    }

    /**
     * Seven in ten deals open (cycling the open stages), two Won, one Lost.
     *
     * @param  array<int, int>  $contactIds
     * @param  array<int, int>  $team
     */
    private function seedDeals(int $organizationId, array $contactIds, array $team, int $total): void
    {
        $contacts = count($contactIds);
        $openStages = DefaultPipeline::stagesOf($organizationId)->where('type', DealStatus::Open)->modelKeys();
        $wonStage = DefaultPipeline::stageOfType($organizationId, DealStatus::Won)->getKey();
        $lostStage = DefaultPipeline::stageOfType($organizationId, DealStatus::Lost)->getKey();

        $this->createInChunks(Deal::factory(), $total, function (int $j) use ($organizationId, $contactIds, $contacts, $team, $openStages, $wonStage, $lostStage): array {
            $attributes = [
                'organization_id' => $organizationId,
                'title' => sprintf('Bench Deal %05d', $j),
                'contact_id' => $contactIds[($j * 5) % $contacts],
                'created_by' => $team[$j % 3],
            ];

            return match (true) {
                $j % 10 <= 6 => [...$attributes, 'status' => DealStatus::Open, 'pipeline_stage_id' => $openStages[$j % count($openStages)]],
                $j % 10 <= 8 => [...$attributes, 'status' => DealStatus::Won, 'pipeline_stage_id' => $wonStage, 'won_at' => now()->subDays($j % 90)],
                default => [...$attributes, 'status' => DealStatus::Lost, 'pipeline_stage_id' => $lostStage, 'lost_at' => now()->subDays($j % 90)],
            };
        });
    }

    /**
     * @param  array<int, int>  $contactIds
     * @param  array<int, int>  $team
     */
    private function seedTasks(int $organizationId, array $contactIds, array $team, int $total): void
    {
        $contacts = count($contactIds);

        $this->createInChunks(Task::factory(), $total, fn (int $t): array => [
            'organization_id' => $organizationId,
            'title' => sprintf('Bench Task %05d', $t),
            'contact_id' => $contactIds[($t * 5) % $contacts],
            'created_by' => $team[$t % 3],
        ]);
    }

    /**
     * @param  array<int, int>  $contactIds
     */
    private function seedInvoices(int $organizationId, array $contactIds, int $total): void
    {
        $contacts = count($contactIds);

        $this->createInChunks(Invoice::factory(), $total, fn (int $v): array => [
            'organization_id' => $organizationId,
            'invoice_number' => sprintf('BENCH-%05d', $v),
            'contact_id' => $contactIds[($v * 10) % $contacts],
        ]);
    }

    /**
     * Rebuilds the memberships of every published segment of the organization once, in this process whatever the
     * queue (the writes above skipped the per-row resyncs).
     */
    private function syncSegments(int $organizationId): void
    {
        $segmentIds = Segment::query()->where('organization_id', $organizationId)->where('is_published', true)->pluck('id');

        foreach ($segmentIds as $segmentId) {
            SyncSegmentMembership::dispatchSync($segmentId);
        }
    }
}
