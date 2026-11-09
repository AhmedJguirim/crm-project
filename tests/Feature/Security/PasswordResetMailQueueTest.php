<?php

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00', 'UTC'));
    $this->ann = User::factory()->create(['email' => 'ann@example.com']);
    $this->genericStatus = 'If an account exists for this email address, we have emailed a password reset link.';
});

test('a known address pushes one encrypted reset job and sends nothing inline', function () {
    Queue::fake();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'ann@example.com'])
        ->assertSessionHas('status', $this->genericStatus);

    Queue::assertPushed(SendQueuedNotifications::class, 1);
    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) {
        return $job->notification instanceof QueuedResetPassword
            && $job->shouldBeEncrypted === true
            && $job->notifiables->modelKeys() === [$this->ann->id]
            && $job->connection === null
            && $job->queue === null;
    });
    expect(DB::table('password_reset_tokens')->where('email', 'ann@example.com')->count())->toBe(1);
});

test('an unknown address pushes nothing and gets the same answer', function () {
    Queue::fake();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'nobody@example.com'])
        ->assertSessionHas('status', $this->genericStatus);

    Queue::assertNothingPushed();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

test('the queued mail is the reset mail of Laravel with a working link', function () {
    Notification::fake();

    $this->from(route('password.request'))->post(route('password.email'), ['email' => 'ann@example.com']);

    Notification::assertSentTo($this->ann, QueuedResetPassword::class, function (QueuedResetPassword $notification) {
        $mail = $notification->toMail($this->ann);
        $row = DB::table('password_reset_tokens')->where('email', 'ann@example.com')->first();

        expect($notification->via($this->ann))->toBe(['mail'])
            ->and($mail->subject)->toBe('Reset Password Notification')
            ->and($mail->actionUrl)->toBe(route('password.reset', ['token' => $notification->token, 'email' => 'ann@example.com']))
            ->and(Hash::check($notification->token, $row->token))->toBeTrue();

        return true;
    });
    Notification::assertNotSentTo($this->ann, ResetPassword::class);
});

test('the plain token is not readable in the stored job payload', function () {
    config(['queue.default' => 'database']);

    $this->from(route('password.request'))->post(route('password.email'), ['email' => 'ann@example.com']);

    expect(DB::table('jobs')->count())->toBe(1);

    $raw = DB::table('jobs')->value('payload');
    $command = unserialize(app('encrypter')->decrypt(json_decode($raw, true)['data']['command']));
    $token = $command->notification->token;

    expect($command)->toBeInstanceOf(SendQueuedNotifications::class)
        ->and($command->notification)->toBeInstanceOf(QueuedResetPassword::class)
        ->and($token)->toBeString()->not->toBeEmpty()
        ->and($raw)->not->toContain($token)
        ->and(Hash::check($token, DB::table('password_reset_tokens')->where('email', 'ann@example.com')->value('token')))->toBeTrue();
});

test('the notification keeps the contract of Laravel', function () {
    $notification = new QueuedResetPassword('any-token');

    expect($notification)->toBeInstanceOf(ResetPassword::class)
        ->toBeInstanceOf(ShouldQueue::class)
        ->toBeInstanceOf(ShouldBeEncrypted::class);
});
