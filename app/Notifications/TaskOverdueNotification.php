<?php

namespace App\Notifications;

use App\Jobs\Middleware\WithTenantContext;
use App\Models\Organization;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Task $task,
        public Organization $organization,
        public string $notificationKey,
    ) {}

    /**
     * Runs the delivery in the task's organization, so what the mail or the database payload loads is scoped to it.
     *
     * @return array<int, object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        return [new WithTenantContext($this->task->organization_id)];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('tasks.notifications.send_email')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Task overdue: '.$this->task->title)
            ->line('Your task "'.$this->task->title.'" is overdue.')
            ->line('Due date: '.$this->task->due_at?->format('M j, Y g:i A'))
            ->action('Open Task', $this->getTaskEditUrl());
    }

    public function toDatabase(object $notifiable): array|DatabaseMessage
    {
        return [
            'title' => 'Task overdue',
            'body' => $this->task->title.' is overdue since '.$this->task->due_at?->format('M j, Y g:i A').'.',
            'task_id' => $this->task->getKey(),
            'organization_id' => $this->organization->getKey(),
            'notification_key' => $this->notificationKey,
            'status' => 'danger',
            'actions' => [
                [
                    'label' => 'Open task',
                    'url' => $this->getTaskEditUrl(),
                ],
            ],
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return (array) $this->toDatabase($notifiable);
    }

    private function getTaskEditUrl(): string
    {
        return url('/admin/'.$this->organization->slug.'/tasks/'.$this->task->getKey().'/edit');
    }
}
