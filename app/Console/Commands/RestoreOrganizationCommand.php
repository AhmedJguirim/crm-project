<?php

namespace App\Console\Commands;

use App\Actions\Organization\RestoreOrganization;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

class RestoreOrganizationCommand extends Command
{
    protected $signature = 'organizations:restore {id : The id of the deleted organization} {--force : Restore without asking for confirmation}';

    protected $description = 'Restore an organization deleted less than 30 days ago, before it is purged';

    /**
     * Operator-only (SEC-52): validates the id, shows what will come back, asks for confirmation unless `--force`, then
     * lets `RestoreOrganization` re-check everything under a row lock.
     */
    public function handle(RestoreOrganization $restore): int
    {
        $raw = $this->argument('id');

        if (! is_string($raw) || preg_match('/\A[1-9][0-9]*\z/', $raw) !== 1 || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            return $this->refuse('The organization id must be a whole number above 0.');
        }

        $id = (int) $raw;
        $organization = Organization::withTrashed()->find($id);
        $refusal = $restore->refusalFor($id, $organization);

        if ($refusal !== null || $organization === null) {
            return $this->refuse((string) $refusal);
        }

        if (! $this->option('force')) {
            $count = $this->memberCount($id);
            $deletedOn = $organization->deleted_at?->clone()->utc()->format('Y-m-d H:i');
            $name = $this->displayName($organization);

            $question = "Restore organization #{$id} \"{$name}\", deleted on {$deletedOn} UTC, with {$count} ".Str::plural('member', $count).'?';

            if (! $this->confirm($question)) {
                $this->line('Nothing was restored.');

                return self::FAILURE;
            }
        }

        $refusal = $restore->handle($id);

        if ($refusal !== null) {
            return $this->refuse($refusal);
        }

        $count = $this->memberCount($id);
        $name = $this->displayName($organization);

        $this->info("Restored organization #{$id} \"{$name}\". {$count} ".Str::plural('member', $count).' '.($count === 1 ? 'was' : 'were').' notified.');

        return self::SUCCESS;
    }

    private function memberCount(int $organizationId): int
    {
        return DB::table('organization_user')->where('organization_id', $organizationId)->count();
    }

    /**
     * Prints a refusal. The sentence may carry the organization name, which is user text, so it is cleaned like the
     * name is everywhere else in the output.
     */
    private function refuse(string $message): int
    {
        $this->line('<error>'.$this->plain($message).'</error>');

        return self::FAILURE;
    }

    /**
     * The organization name as literal text in the terminal: control characters (ANSI escapes) removed, console tags
     * escaped.
     */
    private function displayName(Organization $organization): string
    {
        return $this->plain($organization->name);
    }

    private function plain(string $text): string
    {
        return OutputFormatter::escape(preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '');
    }
}
