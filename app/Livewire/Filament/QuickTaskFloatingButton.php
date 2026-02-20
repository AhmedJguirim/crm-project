<?php

namespace App\Livewire\Filament;

use App\Filament\Actions\QuickTaskAction;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class QuickTaskFloatingButton extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function quickTaskAction(): Action
    {
        return QuickTaskAction::makeGlobal();
    }

    public function render(): View
    {
        return view('livewire.filament.quick-task-floating-button');
    }
}
