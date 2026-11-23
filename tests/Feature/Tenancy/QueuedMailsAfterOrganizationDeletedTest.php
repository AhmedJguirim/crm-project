<?php

use App\Actions\Organization\DeleteOrganization;
use App\Actions\Organization\RestoreOrganization;
use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDailyDigestNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * BUG-83: a mail or notification queued before an organization is deleted is dropped when the worker runs it.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-02-21 08:00:00', 'UTC'));

    $this->olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Olga Owner', 'email' => 'olga@example.com']);
    $this->mia = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'mia@example.com', 'task_reminder_mails' => true]);

    $this->acme = Organization::factory()->timezone('UTC')->create(['name' => 'Acme Studio', 'created_by' => $this->olga->id]);
    $this->acme->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);
    $this->acme->members()->attach($this->mia->id, ['role' => OrganizationRole::Member->value]);

    config(['tasks.notifications.send_email' => true]);

    $this->token = Str::random(64);
    $this->invite = OrganizationInvite::factory()->withToken($this->token)->create([
        'organization_id' => $this->acme->id,
        'email' => 'new@example.com',
        'status' => InviteStatus::Pending,
        'invited_by' => $this->olga->id,
        'expires_at' => '2026-02-28 08:00:00',
    ]);

    $this->task = Task::factory()->create([
        'organization_id' => $this->acme->id,
        'created_by' => $this->olga->id,
        'owner_id' => $this->mia->id,
        'title' => 'Call Acme',
        'status' => TaskStatus::Pending,
        'due_at' => '2026-02-21 07:30:00',
    ]);

    $this->sentMessages = fn (): array => app('mailer')->getSymfonyTransport()->messages()->all();
    $this->deleteAcme = fn (): ?string => app(DeleteOrganization::class)->handle($this->olga, $this->acme);
    $this->bellRows = fn (string $class): int => DB::table('notifications')
        ->where('notifiable_id', $this->mia->id)
        ->where('type', $class)
        ->count();
});

test('an invitation mail for a live organization is still sent', function () {
    Mail::to('new@example.com')->send(new OrganizationInvitationMail($this->invite, $this->token));

    $messages = ($this->sentMessages)();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe('new@example.com')
        ->and($messages[0]->getOriginalMessage()->getSubject())->toBe("You've been invited to join Acme Studio");
});

test('an owner deleting the organization right after inviting someone: the invitee gets no mail', function () {
    $mail = new OrganizationInvitationMail($this->invite, $this->token);

    expect(($this->deleteAcme)())->toBeNull();

    Mail::to('new@example.com')->send($mail);

    expect(($this->sentMessages)())->toHaveCount(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('an invitation hard-deleted after the mail was queued: nothing is sent and no job fails', function () {
    $mail = new OrganizationInvitationMail($this->invite, $this->token);

    $this->invite->delete();

    Mail::to('new@example.com')->send($mail);

    expect(($this->sentMessages)())->toHaveCount(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(OrganizationInvite::query()->count())->toBe(0);
});

test('removing the invited person right after inviting them: the queued mail is dropped quietly', function () {
    $newPerson = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'New Person', 'email' => 'new@example.com']);
    $mail = new OrganizationInvitationMail($this->invite, $this->token);

    $this->acme->deleteInvitesFor($newPerson);

    Mail::to('new@example.com')->send($mail);

    expect(($this->sentMessages)())->toHaveCount(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(OrganizationInvite::query()->where('organization_id', $this->acme->id)->count())->toBe(0);
});

test('an invitation row removed by the organization purge cascade is dropped quietly', function () {
    $mail = new OrganizationInvitationMail($this->invite, $this->token);

    $this->acme->forceDelete();

    Mail::to('new@example.com')->send($mail);

    expect(($this->sentMessages)())->toHaveCount(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('only a missing invite is swallowed: another invite of the same mail still restores and sends', function () {
    $otherToken = Str::random(64);
    $other = OrganizationInvite::factory()->withToken($otherToken)->create([
        'organization_id' => $this->acme->id,
        'email' => 'other@example.com',
        'status' => InviteStatus::Pending,
        'invited_by' => $this->olga->id,
        'expires_at' => '2026-02-28 08:00:00',
    ]);
    $this->invite->delete();

    Mail::to('other@example.com')->send(new OrganizationInvitationMail($other, $otherToken));

    expect(($this->sentMessages)())->toHaveCount(1);
});

test('send called directly on a stale mailable after the deletion returns null', function () {
    $mail = new OrganizationInvitationMail($this->invite, $this->token);
    $this->acme->delete();

    expect($this->invite->fresh()->status)->toBe(InviteStatus::Pending)
        ->and($mail->send(app(Factory::class)))->toBeNull()
        ->and(($this->sentMessages)())->toHaveCount(0);
});

test('an invitation that is no longer valid when the worker runs is not mailed', function (string $state) {
    $mail = new OrganizationInvitationMail($this->invite, $this->token);

    match ($state) {
        'revoked' => $this->invite->update(['status' => InviteStatus::Revoked]),
        'expired' => $this->invite->update(['status' => InviteStatus::Expired, 'expires_at' => '2026-02-20 08:00:00']),
        'expired-but-still-pending' => $this->invite->update(['expires_at' => '2026-02-20 08:00:00']),
        'accepted' => $this->invite->update(['status' => InviteStatus::Accepted]),
    };

    Mail::to('new@example.com')->send($mail);

    expect(($this->sentMessages)())->toHaveCount(0);
})->with(['revoked', 'expired', 'expired-but-still-pending', 'accepted']);

test('the mail re-reads the invite and its organization: a stale mailable sends the stored name', function () {
    $mail = new OrganizationInvitationMail($this->invite, $this->token);
    $this->acme->update(['name' => 'Acme Renamed']);

    Mail::to('new@example.com')->send($mail);

    $messages = ($this->sentMessages)();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getOriginalMessage()->getSubject())->toBe("You've been invited to join Acme Renamed");
});

test('send called directly on a stale mailable re-reads the invite: revoked or gone sends nothing', function (string $state) {
    $mail = new OrganizationInvitationMail($this->invite, $this->token);

    match ($state) {
        'revoked' => OrganizationInvite::query()->whereKey($this->invite->id)->update(['status' => InviteStatus::Revoked]),
        'gone' => OrganizationInvite::query()->whereKey($this->invite->id)->delete(),
    };

    expect($mail->invite->status)->toBe(InviteStatus::Pending)
        ->and($mail->send(app(Factory::class)))->toBeNull()
        ->and(($this->sentMessages)())->toHaveCount(0);
})->with(['revoked', 'gone']);

test('send called directly uses the stored invite and organization, not the stale loaded ones', function () {
    $mail = (new OrganizationInvitationMail($this->invite, $this->token))->to('new@example.com');
    expect($mail->invite->organization->name)->toBe('Acme Studio');

    Organization::query()->whereKey($this->acme->id)->update(['name' => 'Acme Renamed']);

    $mail->send(app(Factory::class));

    $messages = ($this->sentMessages)();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getOriginalMessage()->getSubject())->toBe("You've been invited to join Acme Renamed");
});

test('a task reminder still drops the mail and keeps the bell when the user switched the mails off', function () {
    $this->mia->forceFill(['task_reminder_mails' => false])->save();

    $this->mia->notify(new TaskOverdueNotification($this->task, $this->acme, 'overdue:key-1'));

    expect(($this->bellRows)(TaskOverdueNotification::class))->toBe(1)
        ->and(($this->sentMessages)())->toHaveCount(0);
});

test('a former member of a deleted organization gets no task reminder, neither mail nor bell', function (string $kind) {
    $notification = match ($kind) {
        'due-soon' => new TaskDueSoonNotification($this->task, $this->acme, 'due-soon:key-1'),
        'overdue' => new TaskOverdueNotification($this->task, $this->acme, 'overdue:key-1'),
        default => new TaskDailyDigestNotification($this->acme, collect([$this->task]), collect()),
    };

    expect(($this->deleteAcme)())->toBeNull();

    $this->mia->notify($notification);

    expect(($this->bellRows)($notification::class))->toBe(0)
        ->and(($this->sentMessages)())->toHaveCount(0);
})->with(['due-soon', 'overdue', 'digest']);

test('shouldSend refuses every channel once the organization is deleted, even with a stale model', function () {
    $notification = new TaskOverdueNotification($this->task, $this->acme, 'overdue:key-1');
    $method = new ReflectionMethod($notification, 'shouldSend');

    expect($notification->shouldSend($this->mia->fresh(), 'database'))->toBeTrue()
        ->and($notification->shouldSend($this->mia->fresh(), 'mail'))->toBeTrue();

    $deleted = Organization::query()->find($this->acme->id);
    $deleted->delete();

    expect($this->acme->trashed())->toBeFalse()
        ->and($notification->shouldSend($this->mia->fresh(), 'database'))->toBeFalse()
        ->and($notification->shouldSend($this->mia->fresh(), 'mail'))->toBeFalse()
        ->and($method->isPublic())->toBeTrue();
});

test('a task reminder for a live organization is still delivered on both channels', function () {
    $this->mia->notify(new TaskOverdueNotification($this->task, $this->acme, 'overdue:key-1'));

    $messages = ($this->sentMessages)();

    expect(($this->bellRows)(TaskOverdueNotification::class))->toBe(1)
        ->and($messages)->toHaveCount(1)
        ->and($messages[0]->getOriginalMessage()->getSubject())->toBe('Task overdue: Call Acme');
});

test('a live organization does not block the other organization\'s reminders', function () {
    $other = Organization::factory()->timezone('UTC')->create(['created_by' => $this->olga->id]);
    $other->members()->attach($this->mia->id, ['role' => OrganizationRole::Member->value]);
    $task = Task::factory()->create([
        'organization_id' => $other->id,
        'created_by' => $this->olga->id,
        'owner_id' => $this->mia->id,
        'title' => 'Other task',
        'status' => TaskStatus::Pending,
        'due_at' => '2026-02-21 07:30:00',
    ]);

    expect(($this->deleteAcme)())->toBeNull();

    $this->mia->notify(new TaskOverdueNotification($task, $other, 'overdue:key-2'));

    expect(($this->bellRows)(TaskOverdueNotification::class))->toBe(1)
        ->and(($this->sentMessages)())->toHaveCount(1);
});

test('a reminder is delivered again after the organization is restored', function () {
    expect(($this->deleteAcme)())->toBeNull()
        ->and(app(RestoreOrganization::class)->handle($this->acme->id))->toBeNull();

    $this->mia->notify(new TaskOverdueNotification($this->task, $this->acme->fresh(), 'overdue:key-1'));

    expect(($this->bellRows)(TaskOverdueNotification::class))->toBe(1);
});
