<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationUser;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use UnitEnum;

class OrganizationMembers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Members';

    protected static ?string $title = 'Organization Members';

    protected string $view = 'filament.pages.tenancy.organization-members';

    protected static string|UnitEnum|null $navigationGroup = 'Organization';

    public static function canAccess(): bool
    {
        /** @var Organization|null $organization */
        $organization = Filament::getTenant();

        if (! $organization) {
            return false;
        }

        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $organization->isAdminOrOwner($user);
    }

    public function table(Table $table): Table
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();

        return $table
            ->query(
                OrganizationUser::query()->where('organization_id', $organization->id)->with('user')
            )
            ->columns([
                TextColumn::make('user.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->color(fn (OrganizationRole $state): string => $state->color())
                    ->formatStateUsing(fn (OrganizationRole $state): string => $state->label()),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label('Change Role')
                    ->icon('heroicon-o-pencil-square')
                    ->schema([
                        Select::make('role')
                            ->options([
                                OrganizationRole::Admin->value => OrganizationRole::Admin->label(),
                                OrganizationRole::Member->value => OrganizationRole::Member->label(),
                                OrganizationRole::Viewer->value => OrganizationRole::Viewer->label(),
                            ])
                            ->required(),
                    ])
                    ->fillForm(fn (OrganizationUser $record) => ['role' => $record->role])
                    ->action(function (OrganizationUser $record, array $data) use ($organization): void {
                        $organization->members()->updateExistingPivot($record->user_id, [
                            'role' => $data['role'],
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Role updated')
                            ->body("{$record->user->name}'s role has been changed to ".OrganizationRole::from($data['role'])->label().'.')
                            ->send();
                    })

                    ->visible(function (OrganizationUser $record) use ($organization): bool {
                        /** @var User $user */
                        $user = auth()->user();

                        if ($organization->isOwner($record->user_id)) {
                            return false;
                        }

                        return $organization->isAdminOrOwner($user) && $record->user_id !== $user->id;
                    }),

                Action::make('removeMember')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove Member')
                    ->modalDescription(fn (OrganizationUser $record) => "Are you sure you want to remove {$record->user->name} from this organization? This action is irreversible.")
                    ->action(function (OrganizationUser $record) use ($organization): void {
                        $organization->members()->detach($record->user_id);

                        Notification::make()
                            ->success()
                            ->title('Member removed')
                            ->body("{$record->user->name} has been removed from the organization.")
                            ->send();
                    })

                    ->visible(function (OrganizationUser $record) use ($organization): bool {
                        /** @var User $user */
                        $user = auth()->user();

                        if ($record->user_id === $user->id) {
                            return false;
                        }

                        if ($organization->isOwner($record->user_id)) {
                            return false;
                        }

                        return $organization->isAdminOrOwner($user);
                    }),
            ])
            ->headerActions([
                // ── View pending invitations in a modal ──
                Action::make('pendingInvitations')
                    ->label('Pending Invitations')
                    ->icon('heroicon-o-clock')
                    ->color('warning')
                    ->badge(fn (): int => $this->getPendingInvitesCount())
                    ->badgeColor('warning')
                    ->modalHeading('Pending Invitations')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn () => view('filament.pages.tenancy.pending-invitations'))
                    ->visible(fn (): bool => $this->getPendingInvitesCount() > 0),

                // ── Invite a new user by email ──
                Action::make('inviteMember')
                    ->label('Invite Member')
                    ->icon('heroicon-o-envelope')
                    ->color('primary')
                    ->schema([
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        Select::make('role')
                            ->label('Role')
                            ->options([
                                OrganizationRole::Admin->value => OrganizationRole::Admin->label(),
                                OrganizationRole::Member->value => OrganizationRole::Member->label(),
                                OrganizationRole::Viewer->value => OrganizationRole::Viewer->label(),
                            ])
                            ->default(OrganizationRole::Member->value)
                            ->required(),
                    ])
                    ->action(function (array $data) use ($organization): void {
                        $email = strtolower($data['email']);

                        // Block inviting someone who is already a member
                        $existingMember = $organization->members()
                            ->where('email', $email)
                            ->exists();

                        if ($existingMember) {
                            Notification::make()
                                ->danger()
                                ->title('Already a member')
                                ->body('This user is already a member of this organization.')
                                ->send();

                            return;
                        }

                        // Block duplicate pending invites for the same email
                        if ($organization->hasPendingInviteFor($email)) {
                            Notification::make()
                                ->warning()
                                ->title('Invite already pending')
                                ->body('A pending invitation already exists for this email address.')
                                ->send();

                            return;
                        }

                        // Create the invite record with a secure token
                        $invite = OrganizationInvite::create([
                            'organization_id' => $organization->id,
                            'email' => $email,
                            'token' => OrganizationInvite::generateToken(),
                            'role' => $data['role'],
                            'status' => InviteStatus::Pending,
                            'invited_by' => auth()->id(),
                            'expires_at' => now()->addDays(7),
                        ]);

                        // Queue the invitation email
                        Mail::to($email)->send(new OrganizationInvitationMail($invite));

                        Notification::make()
                            ->success()
                            ->title('Invitation sent')
                            ->body("An invitation has been sent to {$email}.")
                            ->send();
                    }),

                // ── Transfer ownership to another member ──
                Action::make('transferOwnership')
                    ->label('Transfer Ownership')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->schema(function () use ($organization): array {
                        /** @var User $user */
                        $user = auth()->user();

                        $members = $organization->members()
                            ->where('user_id', '!=', $user->id)
                            ->pluck('name', 'users.id');

                        return [
                            Select::make('new_owner_id')
                                ->label('Transfer ownership to')
                                ->options($members)
                                ->required()
                                ->helperText('You will be demoted to Admin. This action cannot be undone.'),
                        ];
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Transfer Ownership')
                    ->modalDescription('Are you sure you want to transfer ownership? You will become an Admin.')
                    ->action(function (array $data) use ($organization): void {
                        /** @var User $currentOwner */
                        $currentOwner = auth()->user();

                        DB::transaction(function () use ($organization, $currentOwner, $data): void {
                            $organization->members()->updateExistingPivot($currentOwner->id, [
                                'role' => OrganizationRole::Admin->value,
                            ]);

                            $organization->members()->updateExistingPivot($data['new_owner_id'], [
                                'role' => OrganizationRole::Owner->value,
                            ]);
                        });

                        $newOwner = User::find($data['new_owner_id']);

                        Notification::make()
                            ->success()
                            ->title('Ownership transferred')
                            ->body("Ownership has been transferred to {$newOwner->name}.")
                            ->send();
                    })
                    ->visible(function () use ($organization): bool {
                        /** @var User $user */
                        $user = auth()->user();

                        return $organization->isOwner($user)
                            && $organization->members()->count() > 1;
                    }),
            ]);
    }

    /**
     * Fetch all pending/expired invites for the current organization.
     * Displayed in the "Pending Invitations" section below the members table.
     */
    public function getPendingInvitesCount(): int
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();

        return $organization->invitations()
            ->whereIn('status', [InviteStatus::Pending->value])
            ->orderByDesc('created_at')
            ->count();
    }
}
