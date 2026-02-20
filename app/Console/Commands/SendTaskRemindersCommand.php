<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class SendTaskRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send due-tomorrow and overdue reminders for pending tasks';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dueTomorrowTasks = Task::query()
            ->with('organization')
            ->where('status', TaskStatus::Pending)
            ->whereNotNull('due_at')
            ->whereDate('due_at', now()->addDay()->toDateString())
            ->get();

        $overdueTasks = Task::query()
            ->with('organization')
            ->where('status', TaskStatus::Pending)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->get();

        $this->sendDueSoonNotifications($dueTomorrowTasks);
        $this->sendOverdueNotifications($overdueTasks);

        $this->info('Task reminders sent successfully.');

        return self::SUCCESS;
    }

    private function sendDueSoonNotifications($tasks): void
    {
        foreach ($tasks as $task) {
            $organization = $task->organization;

            if (! $organization instanceof Organization) {
                continue;
            }

            $notificationKey = 'due-soon:'.$task->getKey().':'.$task->due_at?->toDateString();

            foreach ($organization->members as $member) {
                if (! $member instanceof User || $this->alreadyNotified($member, TaskDueSoonNotification::class, $notificationKey)) {
                    continue;
                }

                $member->notify(new TaskDueSoonNotification($task, $organization, $notificationKey));
            }
        }
    }

    private function sendOverdueNotifications($tasks): void
    {
        foreach ($tasks as $task) {
            $organization = $task->organization;

            if (! $organization instanceof Organization) {
                continue;
            }

            $notificationKey = 'overdue:'.$task->getKey().':'.now()->toDateString();

            foreach ($organization->members as $member) {
                if (! $member instanceof User || $this->alreadyNotified($member, TaskOverdueNotification::class, $notificationKey)) {
                    continue;
                }

                $member->notify(new TaskOverdueNotification($task, $organization, $notificationKey));
            }
        }
    }

    private function alreadyNotified(User $user, string $type, string $notificationKey): bool
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->getKey())
            ->where('type', $type)
            ->where('data->notification_key', $notificationKey)
            ->exists();
    }
}
