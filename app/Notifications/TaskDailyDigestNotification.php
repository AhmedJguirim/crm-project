<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class TaskDailyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Organization $organization,
        public Collection $overdueTasks,
        public Collection $dueTodayTasks,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Daily task digest - '.$this->organization->name)
            ->line('Here is your daily task summary for '.$this->organization->name.'.')
            ->line('Overdue tasks: '.$this->overdueTasks->count())
            ->line('Due today tasks: '.$this->dueTodayTasks->count());

        foreach ($this->overdueTasks->take(5) as $task) {
            $mail->line('- OVERDUE: '.$task->title.' ('.$task->due_at?->format('M j, Y g:i A').')');
        }

        foreach ($this->dueTodayTasks->take(5) as $task) {
            $mail->line('- TODAY: '.$task->title.' ('.$task->due_at?->format('M j, Y g:i A').')');
        }

        return $mail->action('Open Tasks', url('/admin/'.$this->organization->slug.'/tasks'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
