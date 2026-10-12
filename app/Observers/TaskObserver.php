<?php

namespace App\Observers;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

class TaskObserver
{
    public function creating(Task $task): void
    {
        if (! $task->organization_id && $organizationId = app(TenantContext::class)->id()) {
            $task->organization_id = $organizationId;
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
