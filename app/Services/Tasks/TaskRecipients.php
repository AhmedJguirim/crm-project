<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Decides who is notified about a pending task: its assignee when the assignee is a current member of the
 * organization, every member otherwise (no assignee, or an assignee who left the organization).
 */
final class TaskRecipients
{
    /**
     * @param  EloquentCollection<int, User>  $members  the organization's members, already loaded
     */
    public static function includes(Task $task, User $member, EloquentCollection $members): bool
    {
        return $task->owner_id === null
            || $members->find($task->owner_id) === null
            || $task->owner_id === $member->getKey();
    }

    /**
     * @param  EloquentCollection<int, User>  $members  the organization's members, already loaded
     * @return EloquentCollection<int, User>
     */
    public static function for(Task $task, EloquentCollection $members): EloquentCollection
    {
        return $members->filter(fn (User $member): bool => self::includes($task, $member, $members))->values();
    }
}
