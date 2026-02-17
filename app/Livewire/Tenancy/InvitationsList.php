<?php

namespace App\Livewire\Tenancy;

use App\Enums\InviteStatus;
use App\Mail\OrganizationInvitationMail;
use App\Models\OrganizationInvite;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class InvitationsList extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getPendingInvites())
            ->columns([
                TextColumn::make('email'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (InviteStatus $state): string => $state->color())
                    ->formatStateUsing(fn (InviteStatus $state): string => $state->label()),
                TextColumn::make('expires_at')
                    ->label('Expires At')
                    ->dateTime(),
            ])
            ->filters([
                // ...
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->color('danger')
                    ->action(function (OrganizationInvite $invite) {
                        $invite->revoke();

                        Notification::make()
                            ->success()
                            ->title('Invitation revoked')
                            ->body("The invitation to {$invite->email} has been revoked.")
                            ->send();
                        $this->resetTable();
                    }),
                Action::make('resend')
                    ->label('Resend')
                    ->icon('heroicon-o-envelope')
                    ->action(function (OrganizationInvite $invite) {
                        // Regenerate token and extend expiry by 7 days
                        $invite->regenerateToken(7);

                        // Re-send the invitation email with the new token
                        Mail::to($invite->email)->send(new OrganizationInvitationMail($invite->fresh()));

                        Notification::make()
                            ->success()
                            ->title('Invitation resent')
                            ->body("A new invitation email has been sent to {$invite->email}.")
                            ->send();

                        $this->resetTable();
                    }),
            ])
            ->toolbarActions([
                // ...
            ]);
    }

    private function getPendingInvites(): Builder
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();

        return $organization->invitations()
            ->whereIn('status', [InviteStatus::Pending->value])
            ->orderByDesc('created_at')
            ->getQuery();
    }

    public function render(): View
    {
        return view('filament.pages.tenancy.invitations-list');
    }
}
