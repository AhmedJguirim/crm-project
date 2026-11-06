<?php

namespace App\Console\Commands;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\UniqueUserEmail;
use Database\Seeders\ItConsultingSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Creates a login with its own workspace filled with the IT consulting demo data, for a demo on a real server where
 * the dev seed (known emails, plain-text passwords in the repository) must never run. The password is asked at a
 * hidden prompt so it stays out of the shell history. The demo data is spread over the new user and two teammates
 * created with it, who have unknown random passwords and unroutable emails, so nobody can log in as them.
 */
class CreateDemoAccountCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:create-account
        {name : The name of the demo user}
        {email : The email the demo user logs in with}
        {--timezone= : The timezone of the demo workspace (default UTC)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a demo login with its own workspace filled with the IT consulting demo data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm the password');

        $email = (string) $this->argument('email');

        if (config('fortify.lowercase_usernames')) {
            $email = Str::lower($email);
        }

        $validator = Validator::make(
            ['name' => $this->argument('name'), 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', new UniqueUserEmail],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $organization = DB::transaction(function () use ($password, $email): Organization {
            $owner = $this->createUser((string) $this->argument('name'), $email, $password);
            $organization = $owner->createPersonalOrganization($this->option('timezone'));

            $admin = $this->createTeammate($organization, 'Alex Morgan', OrganizationRole::Admin);
            $member = $this->createTeammate($organization, 'Sam Rivera', OrganizationRole::Member);

            app(ItConsultingSeeder::class)->seedDemoOrganization($organization, $owner, $admin, $member);

            return $organization;
        });

        $this->info("Demo account created: {$email} owns the workspace \"{$organization->name}\".");

        return self::SUCCESS;
    }

    private function createUser(string $name, string $email, string $password): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'onboarding_completed' => true]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /**
     * A teammate who owns part of the demo data: a random password nobody knows and an email on the reserved
     * `.invalid` domain, so the account can't be logged into and no mail reaches anyone.
     */
    private function createTeammate(Organization $organization, string $name, OrganizationRole $role): User
    {
        $teammate = $this->createUser($name, 'demo-'.Str::lower(Str::random(16)).'@demo.invalid', Str::random(64));

        $organization->members()->attach($teammate->id, ['role' => $role->value]);

        return $teammate;
    }
}
