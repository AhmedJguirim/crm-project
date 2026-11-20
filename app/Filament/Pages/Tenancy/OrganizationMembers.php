<?php

namespace App\Filament\Pages\Tenancy;

use App\Actions\Organization\TransferOwnership;
use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Filament\Support\Concerns\ResolvesReadableTableRecordKeys;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Filament\Support\RecordKeyField;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Support\NotificationText;
use App\Support\Security\SecurityLog;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\InvitationLimits;
use App\Support\UserFiles;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use UnitEnum;

class OrganizationMembers extends Page implements HasTable
{
    use InteractsWithTable, ResolvesReadableTableRecordKeys {
        InteractsWithTable::getTableRecord as protected getFilamentTableRecord;
    }
    use SanitizesTableSearch;

    /**
     * @return Model|array<string, mixed>|null
     */
    public function getTableRecord(mixed $key): Model|array|null
    {
        return $this->getFilamentTableRecord($this->readableTableRecordKey($key));
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Members';

    protected static ?string $title = 'Organization Members';

    protected string $view = 'filament.pages.tenancy.organization-members';

    protected static string|UnitEnum|null $navigationGroup = 'Organization';

    protected static ?int $navigationSort = 13;

    public static function canAccess(): bool
    {
        /** @var Organization|null $organization */
        $organization = CurrentOrganization::find();

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
        $organization = CurrentOrganization::find();

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
                        $to = OrganizationRole::from($data['role']);

                        $outcome = DB::transaction(function () use ($organization, $record, $data): string|OrganizationRole {
                            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();

                            if ($locked === null) {
                                return 'This organization was deleted.';
                            }

                            $current = DB::table('organization_user')
                                ->where('organization_id', $locked->getKey())
                                ->where('user_id', $record->user_id)
                                ->value('role');

                            if ($current === OrganizationRole::Owner->value) {
                                return "{$record->user->name} is now the owner of this organization. Their role can't be changed.";
                            }

                            if ($current === null) {
                                return "{$record->user->name} is no longer a member of this organization.";
                            }

                            $locked->members()->updateExistingPivot($record->user_id, [
                                'role' => $data['role'],
                            ]);

                            return OrganizationRole::from($current);
                        });

                        if (is_string($outcome)) {
                            Notification::make()
                                ->danger()
                                ->title('Role not changed')
                                ->body(NotificationText::plain($outcome))
                                ->send();

                            return;
                        }

                        if ($outcome !== $to) {
                            SecurityLog::warning('organization.member_role_changed', auth()->user(), [
                                'member_user_id' => $record->user_id,
                                'from_role' => $outcome->value,
                                'to_role' => $to->value,
                            ]);
                        }

                        Notification::make()
                            ->success()
                            ->title('Role updated')
                            ->body(NotificationText::plain("{$record->user->name}'s role has been changed to ".$to->label().'.'))
                            ->send();
                    })
                    ->authorize(fn (OrganizationUser $record): bool => Gate::allows('changeRole', [$organization, $record->user])),

                Action::make('removeMember')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove Member')
                    ->modalDescription(fn (OrganizationUser $record) => "Are you sure you want to remove {$record->user->name} from this organization? This action is irreversible.")
                    ->action(function (OrganizationUser $record) use ($organization): void {
                        $outcome = DB::transaction(function () use ($organization, $record): string|OrganizationRole {
                            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();

                            if ($locked === null) {
                                return 'This organization was deleted.';
                            }

                            $current = DB::table('organization_user')
                                ->where('organization_id', $locked->getKey())
                                ->where('user_id', $record->user_id)
                                ->value('role');

                            if ($current === OrganizationRole::Owner->value) {
                                return "{$record->user->name} is now the owner of this organization and can't be removed.";
                            }

                            if ($current === null) {
                                return "{$record->user->name} is no longer a member of this organization.";
                            }

                            $locked->deleteInvitesFor($record->user);
                            $locked->members()->detach($record->user_id);

                            return OrganizationRole::from($current);
                        });

                        if (is_string($outcome)) {
                            Notification::make()
                                ->danger()
                                ->title('Member not removed')
                                ->body(NotificationText::plain($outcome))
                                ->send();

                            return;
                        }

                        SecurityLog::warning('organization.member_removed', auth()->user(), [
                            'member_user_id' => $record->user_id,
                            'role' => $outcome->value,
                        ]);

                        UserFiles::deleteForMember($record->user_id, $organization->id);

                        Notification::make()
                            ->success()
                            ->title('Member removed')
                            ->body(NotificationText::plain("{$record->user->name} has been removed from the organization."))
                            ->send();
                    })
                    ->authorize(fn (OrganizationUser $record): bool => Gate::allows('removeMember', [$organization, $record->user])),
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
                    ->authorize(fn (): bool => Gate::allows('manageMembers', $organization))
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
                        $email = Str::lower($data['email']);

                        // Block inviting someone who is already a member
                        $existingMember = $organization->members()
                            ->whereEmailIgnoringCase($email)
                            ->exists();

                        if ($existingMember) {
                            Notification::make()
                                ->danger()
                                ->title('Already a member')
                                ->body('This user is already a member of this organization.')
                                ->send();

                            return;
                        }

                        $organization->expireStalePendingInvitesFor($email);

                        // Block duplicate pending invites for the same email
                        if ($organization->hasPendingInviteFor($email)) {
                            Notification::make()
                                ->warning()
                                ->title('Invite already pending')
                                ->body('A pending invitation already exists for this email address.')
                                ->send();

                            return;
                        }

                        /** @var User $inviter */
                        $inviter = auth()->user();

                        $refusal = InvitationLimits::claim($organization, $inviter);

                        if ($refusal !== null) {
                            Notification::make()
                                ->danger()
                                ->title('Too many invitations')
                                ->body(NotificationText::plain($refusal))
                                ->send();

                            return;
                        }

                        // Store only the token's hash; the plain token goes into the mail link
                        $token = OrganizationInvite::generateToken();

                        try {
                            $invite = DB::transaction(fn (): OrganizationInvite => OrganizationInvite::create([
                                'organization_id' => $organization->id,
                                'email' => $email,
                                'token_hash' => OrganizationInvite::hashToken($token),
                                'role' => $data['role'],
                                'status' => InviteStatus::Pending,
                                'invited_by' => auth()->id(),
                                'expires_at' => now()->addDays(7),
                            ]));
                        } catch (UniqueConstraintViolationException) {
                            InvitationLimits::release($organization, $inviter);

                            Notification::make()
                                ->warning()
                                ->title('Invite already pending')
                                ->body('A pending invitation already exists for this email address.')
                                ->send();

                            return;
                        }

                        // Queue the invitation email
                        Mail::to($email)->send(new OrganizationInvitationMail($invite, $token));

                        SecurityLog::info('organization.invitation_sent', $inviter, [
                            'invite_id' => $invite->id,
                            'role' => $invite->role->value,
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Invitation sent')
                            ->body(NotificationText::plain("An invitation has been sent to {$email}."))
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
                            RecordKeyField::select('new_owner_id')
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

                        $refusal = app(TransferOwnership::class)->handle($currentOwner, $organization, (int) $data['new_owner_id']);

                        if ($refusal !== null) {
                            Notification::make()
                                ->danger()
                                ->title('Ownership not transferred')
                                ->body(NotificationText::plain($refusal))
                                ->send();

                            return;
                        }

                        $newOwner = User::find($data['new_owner_id']);

                        Notification::make()
                            ->success()
                            ->title('Ownership transferred')
                            ->body(NotificationText::plain("Ownership has been transferred to {$newOwner->name}."))
                            ->send();
                    })
                    ->authorize(fn (): bool => Gate::allows('transferOwnership', $organization))
                    ->visible(fn (): bool => $organization->members()->count() > 1),
            ]);
    }

    /**
     * Fetch all pending/expired invites for the current organization.
     * Displayed in the "Pending Invitations" section below the members table.
     */
    public function getPendingInvitesCount(): int
    {
        /** @var Organization $organization */
        $organization = CurrentOrganization::find();

        return $organization->invitations()
            ->whereIn('status', [InviteStatus::Pending->value])
            ->orderByDesc('created_at')
            ->count();
    }
}
