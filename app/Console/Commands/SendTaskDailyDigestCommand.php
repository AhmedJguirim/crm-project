<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\Organization;
use App\Models\Task;
use App\Notifications\TaskDailyDigestNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class SendTaskDailyDigestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:send-digest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send daily digest emails for overdue and due-today tasks';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! config('tasks.notifications.daily_digest_enabled')) {
            $this->info('Task daily digest is disabled.');

            return self::SUCCESS;
        }

        $organizations = Organization::query()->with('members')->get();

        /** @var Organization $organization */
        foreach ($organizations as $organization) {
            app(TenantContext::class)->run($organization->getKey(), fn () => $this->sendDigest($organization));
        }

        $this->info('Task daily digest sent successfully.');

        return self::SUCCESS;
    }

    private function sendDigest(Organization $organization): void
    {
        $overdueTasks = Task::query()
            ->where('status', TaskStatus::Pending)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->orderBy('due_at')
            ->get();

        $dueTodayTasks = Task::query()
            ->where('status', TaskStatus::Pending)
            ->whereDate('due_at', now()->toDateString())
            ->orderBy('due_at')
            ->get();

        if ($overdueTasks->isEmpty() && $dueTodayTasks->isEmpty()) {
            return;
        }

        foreach ($organization->members as $member) {
            $member->notify(new TaskDailyDigestNotification($organization, $overdueTasks, $dueTodayTasks));
        }
    }
}
