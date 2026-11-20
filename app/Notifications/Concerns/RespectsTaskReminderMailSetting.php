<?php

namespace App\Notifications\Concerns;

use App\Models\User;

trait RespectsTaskReminderMailSetting
{
    /**
     * Whether a task reminder mail may go to this notifiable: a verified user who did not switch them off.
     */
    protected function mailsTaskRemindersTo(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->wantsTaskReminderMails();
    }

    /**
     * Re-checked when the queued mail is delivered, so a user who opts out after it was queued gets nothing.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $channel !== 'mail' || $this->mailsTaskRemindersTo($notifiable);
    }
}
