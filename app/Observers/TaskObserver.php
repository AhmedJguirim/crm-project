<?php

namespace App\Observers;

use App\Enums\TaskStatus;
use App\Models\Task;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

class TaskObserver
{
    public function creating(Task $task): void
    {
        if (! $task->organization_id && Filament::getTenant()) {
            $task->organization_id = Filament::getTenant()->id;
        }

        $authUserId = Auth::id();

        if (! $task->created_by && $authUserId) {
            $task->created_by = $authUserId;
        }

        if (! $task->status) {
            $task->status = TaskStatus::Pending;
        }
    }
}
