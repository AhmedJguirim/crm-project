<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\OrganizationUser;
use App\Support\Onboarding\GettingStartedChecklist;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * The "Get started" card above the dashboard: the four first steps (a contact, a deal, a task, an invoice), each ticked
 * once the organization has one. Owners, admins and members see it until all four are done or they dismiss it; the
 * dismissal is stored per user and organization on the membership.
 */
class GettingStartedWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.widgets.getting-started-widget';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public bool $isDismissed = false;

    public static function canView(): bool
    {
        $checklist = GettingStartedChecklist::forCurrentUser();

        return $checklist !== null && ! $checklist->isComplete();
    }

    public function dismissAction(): Action
    {
        return Action::make('dismiss')
            ->label('Dismiss')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->link()
            ->authorize(fn (): bool => GettingStartedChecklist::forCurrentUser() !== null)
            ->action(function (): void {
                $organization = CurrentOrganization::find();
                $userId = Auth::id();

                if ($organization === null || $userId === null) {
                    return;
                }

                OrganizationUser::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('user_id', $userId)
                    ->update(['checklist_dismissed_at' => now()]);

                $this->isDismissed = true;
            });
    }

    /**
     * @return array{steps: list<array{label: string, url: ?string, done: bool}>, doneCount: int}
     */
    protected function getViewData(): array
    {
        $checklist = GettingStartedChecklist::forCurrentUser();

        if ($checklist === null) {
            return ['steps' => [], 'doneCount' => 0];
        }

        $step = fn (string $label, bool $done, string $resource): array => [
            'label' => $label,
            'url' => ! $done && $resource::canCreate() ? $resource::getUrl('create') : null,
            'done' => $done,
        ];

        return [
            'steps' => [
                $step('Add a contact', $checklist->hasContact, ContactResource::class),
                $step('Create a deal', $checklist->hasDeal, DealResource::class),
                $step('Add a task', $checklist->hasTask, TaskResource::class),
                $step('Create an invoice', $checklist->hasInvoice, InvoiceResource::class),
            ],
            'doneCount' => $checklist->doneCount(),
        ];
    }
}
