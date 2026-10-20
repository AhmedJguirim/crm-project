<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Models\Task;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The logged-in user's pending tasks that are overdue or due today (organization timezone), with the tasks list's
 * Snooze and Mark Done actions. In an organization with a single member, unassigned tasks are listed too, because
 * nobody else can do them; in a team they are only counted in the description.
 */
class MyTasksWidget extends TableWidget
{
    use SanitizesTableSearch;

    protected static ?int $sort = -40;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $solo = $this->isSoloOrganization();

        return $table
            ->query(fn (): Builder => $this->tasksQuery())
            ->heading('My tasks')
            ->description($this->description($solo))
            ->columns([
                TextColumn::make('title')
                    ->label('Task')
                    ->limit(50)
                    ->tooltip(fn (string $state): ?string => strlen($state) > 50 ? $state : null)
                    ->url(fn (Task $record): ?string => TaskResource::canEdit($record)
                        ? TaskResource::getUrl('edit', ['record' => $record])
                        : null),

                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->placeholder('—'),

                TextColumn::make('due_at')
                    ->label('Due')
                    ->dateTime('M j, Y g:i A')
                    ->badge()
                    ->color(fn (Task $record): string => $record->isOverdue() ? 'danger' : 'warning'),

                TextColumn::make('priority')
                    ->badge(),
            ])
            ->headerActions([
                Action::make('seeAll')
                    ->label('See all my tasks')
                    ->link()
                    ->url($solo
                        ? TaskResource::getUrl('index')
                        : TaskResource::getUrl('index', ['tab' => 'mine'])),
            ])
            ->recordActions([
                TasksTable::snoozeActionGroup(),
                TasksTable::markDoneAction(),
            ])
            ->paginated([5])
            ->poll('30s')
            ->emptyStateHeading('Nothing due today')
            ->emptyStateDescription('You have no overdue tasks and nothing due today.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    /**
     * Solo means exactly one member in the current organization; this is decided by the member count, not by the
     * personal flag, because a personal organization can invite members.
     */
    protected function isSoloOrganization(): bool
    {
        return CurrentOrganization::find()?->members()->count() === 1;
    }

    /**
     * Pending tasks with a due date up to the end of today (organization timezone), oldest first; a team member
     * only sees the tasks assigned to them, a solo user also the unassigned ones.
     *
     * @return Builder<Task>
     */
    protected function tasksQuery(): Builder
    {
        $userId = Auth::id();
        $solo = $this->isSoloOrganization();

        return $this->dueTodayOrEarlier()
            ->where(function (Builder $query) use ($userId, $solo): void {
                $query->where('owner_id', $userId);

                if ($solo) {
                    $query->orWhereNull('owner_id');
                }
            })
            ->with('contact')
            ->orderBy('due_at')
            ->orderBy('id');
    }

    /**
     * @return Builder<Task>
     */
    protected function dueTodayOrEarlier(): Builder
    {
        return Task::query()
            ->where('status', TaskStatus::Pending)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', LocalTime::period('day')[1]);
    }

    protected function description(bool $solo): string
    {
        if ($solo) {
            return 'Your overdue tasks and tasks due today, including unassigned ones.';
        }

        $unassigned = $this->dueTodayOrEarlier()->whereNull('owner_id')->count();

        return match (true) {
            $unassigned === 0 => 'Your overdue tasks and tasks due today.',
            $unassigned === 1 => 'Your overdue tasks and tasks due today. 1 unassigned task is overdue or due today.',
            default => "Your overdue tasks and tasks due today. {$unassigned} unassigned tasks are overdue or due today.",
        };
    }
}
