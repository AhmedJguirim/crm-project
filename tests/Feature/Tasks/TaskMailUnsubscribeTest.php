<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDailyDigestNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function tmuUrlFor(User $user, ?string $email = null): string
{
    return URL::signedRoute('task-mails.unsubscribe', ['id' => $user->getKey(), 'hash' => sha1($email ?? $user->email)]);
}

beforeEach(function () {
    config(['tasks.notifications.send_email' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-02-21 08:00:00', 'UTC'));

    $this->ann = User::factory()->onboardingCompleted()->create(['email' => 'ann@example.com']);
    $this->bob = User::factory()->onboardingCompleted()->create(['email' => 'bob@example.com']);
    $this->org = Organization::factory()->timezone('UTC')->create(['created_by' => $this->ann->id, 'name' => 'Acme']);
    $this->org->members()->attach($this->ann, ['role' => OrganizationRole::Owner->value]);
    $this->task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->ann->id,
        'owner_id' => $this->ann->id,
        'title' => 'Call Acme',
        'status' => TaskStatus::Pending,
        'due_at' => '2026-02-21 07:30:00',
    ]);
    $this->url = tmuUrlFor($this->ann);
    $this->annSetting = fn (): bool => (bool) $this->ann->fresh()->task_reminder_mails;
});

test('each task mail has the two headers and the footer line', function (string $kind) {
    Notification::swap(new ChannelManager(app()));
    $notification = match ($kind) {
        'due-soon' => new TaskDueSoonNotification($this->task, $this->org, 'key-1'),
        'overdue' => new TaskOverdueNotification($this->task, $this->org, 'key-1'),
        default => new TaskDailyDigestNotification($this->org, collect([$this->task]), collect()),
    };

    Notification::sendNow($this->ann, $notification, ['mail']);

    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $email = $messages[0]->getOriginalMessage();
    $html = $email->getHtmlBody();

    expect($email->getTo()[0]->getAddress())->toBe('ann@example.com')
        ->and($email->getHeaders()->get('List-Unsubscribe')->getBodyAsString())->toBe('<'.$this->url.'>')
        ->and($email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
        ->and($html)->toContain('You get this email because task reminder emails are on for your account.')
        ->and($html)->toContain('href="'.$this->url.'"')
        ->and($html)->toContain('href="'.Filament::getPanel('admin')->getProfileUrl().'"')
        ->and($html)->not->toContain(route('notification-settings.edit'));
})->with(['due-soon', 'overdue', 'digest']);

test('the existing mail content is unchanged and the footer is the only outro line', function () {
    $mail = (new TaskOverdueNotification($this->task, $this->org, 'key-1'))->toMail($this->ann);

    expect($mail->subject)->toBe('Task overdue: Call Acme')
        ->and($mail->introLines)->toBe(['Your task "Call Acme" is overdue.', 'Due date: Feb 21, 2026 7:30 AM'])
        ->and($mail->actionText)->toBe('Open Task')
        ->and($mail->actionUrl)->toBe(url('/admin/'.$this->org->slug.'/tasks/'.$this->task->getKey().'/edit'))
        ->and($mail->outroLines)->toHaveCount(1)
        ->and($mail->outroLines[0]->toHtml())->toBe('You get this email because task reminder emails are on for your account. [Unsubscribe]('.$this->url.') or [change your email settings]('.Filament::getPanel('admin')->getProfileUrl().').');
});

test('a notifiable that is not a user gets the mail without the unsubscribe link', function () {
    $mail = (new TaskOverdueNotification($this->task, $this->org, 'key-1'))->toMail(new stdClass);

    expect($mail->outroLines)->toBe([])
        ->and($mail->callbacks)->toBe([]);
});

test('opening the link shows a confirmation page and changes nothing', function () {
    $response = $this->get($this->url)->assertOk()
        ->assertSee('Stop task reminder emails')
        ->assertSee('data-test="unsubscribe-task-mails-button"', false)
        ->assertDontSee('ann@example.com');

    $path = parse_url($this->url, PHP_URL_PATH).'?'.parse_url($this->url, PHP_URL_QUERY);
    expect($response->getContent())->toContain('action="'.e($path).'"')
        ->and(($this->annSetting)())->toBeTrue();
});

test('the button turns the mails off', function () {
    $this->post($this->url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('You are unsubscribed');

    expect(($this->annSetting)())->toBeFalse();
});

test('a mail client one-click post with an empty body works too', function () {
    $this->post($this->url)->assertOk();

    expect(($this->annSetting)())->toBeFalse();
});

test('unsubscribing twice is harmless', function () {
    $this->ann->forceFill(['task_reminder_mails' => false])->save();

    $this->post($this->url)->assertOk()->assertSee('You are unsubscribed');
    $this->get($this->url)->assertOk()
        ->assertSee('Task reminder emails are already off')
        ->assertSee('You can turn them back on in your profile, under Email notifications.')
        ->assertSee('href="'.Filament::getPanel('admin')->getProfileUrl().'"', false)
        ->assertDontSee('data-test="unsubscribe-task-mails-button"', false);
});

test('after unsubscribing the next run sends the bell but no mail', function () {
    $this->post($this->url)->assertOk();
    Notification::fake();

    Artisan::call('tasks:send-reminders', ['--all' => true]);

    Notification::assertSentTo($this->ann, TaskOverdueNotification::class, fn ($notification, array $channels): bool => $channels === ['database']);
});

test('the post route takes no csrf token and the get route keeps it', function () {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('task-mails.unsubscribe.store')->excludedMiddleware())->toContain(ValidateCsrfToken::class)
        ->and($routes->getByName('task-mails.unsubscribe')->excludedMiddleware())->toBe([]);
});

test('the link never expires', function () {
    expect($this->url)->not->toContain('expires');

    $this->travelTo(CarbonImmutable::parse('2036-02-21 08:00:00', 'UTC'));
    $this->post($this->url)->assertOk();

    expect(($this->annSetting)())->toBeFalse();
});

test('the signed-in user plays no part', function () {
    $this->actingAs($this->bob)->post($this->url)->assertOk();

    expect(($this->annSetting)())->toBeFalse()
        ->and($this->bob->fresh()->task_reminder_mails)->toBeTrue();
});

describe('hostile', function () {
    test('a forged link is refused with the not valid page and writes nothing', function (string $method, Closure $forge) {
        $url = $forge($this->url, $this->ann, $this->bob);

        $this->call($method, $url)->assertForbidden()
            ->assertSee('This link is not valid')
            ->assertSee('Manage email settings');

        expect(($this->annSetting)())->toBeTrue()
            ->and($this->bob->fresh()->task_reminder_mails)->toBeTrue();
    })->with(function (): array {
        $otherId = fn (string $url, User $ann, User $bob): string => str_replace('/'.$ann->getKey().'/', '/'.$bob->getKey().'/', $url);
        $otherHash = fn (string $url, User $ann, User $bob): string => str_replace(sha1($ann->email), sha1($bob->email), $url);
        $badSignature = fn (string $url): string => substr($url, 0, -1).(str_ends_with($url, '0') ? '1' : '0');
        $noSignature = fn (string $url): string => explode('?', $url)[0];
        $expires = fn (string $url): string => $url.'&expires=1';

        return [
            'other id, GET' => ['GET', $otherId],
            'other id, POST' => ['POST', $otherId],
            'other hash, POST' => ['POST', $otherHash],
            'bad signature, POST' => ['POST', $badSignature],
            'no signature, POST' => ['POST', $noSignature],
            'expires added, POST' => ['POST', $expires],
        ];
    });

    test('a link mailed before an email change no longer works', function () {
        $this->ann->forceFill(['email' => 'ann.new@example.com'])->save();

        $this->post($this->url)->assertNotFound()->assertSee('This link is not valid');

        expect(($this->annSetting)())->toBeTrue();
    });

    test('a link for a deleted user shows the not valid page', function () {
        $carl = User::factory()->onboardingCompleted()->create(['email' => 'carl@example.com']);
        $url = tmuUrlFor($carl);
        $carl->delete();

        $this->get($url)->assertNotFound()->assertSee('This link is not valid');
    });

    test('an out of pattern path is a plain 404', function (string $path) {
        $this->post(str_replace(['{id}', '{hash}'], [(string) $this->ann->getKey(), sha1('ann@example.com')], $path))->assertNotFound();

        expect(($this->annSetting)())->toBeTrue();
    })->with([
        'letters as id' => '/task-emails/unsubscribe/abc/{hash}',
        '19-digit id' => '/task-emails/unsubscribe/1234567890123456789/{hash}',
        'junk hash' => '/task-emails/unsubscribe/{id}/XYZ',
    ]);

    test('a flood of requests from one IP is throttled with a readable page', function () {
        foreach (range(1, 20) as $attempt) {
            $this->get($this->url)->assertOk();
        }

        $this->get($this->url)->assertStatus(429)
            ->assertSee('Too many requests')
            ->assertSee('Please try again in 1 minute.');

        expect(($this->annSetting)())->toBeTrue();
    });

    test('a throttled post writes nothing', function () {
        foreach (range(1, 20) as $attempt) {
            $this->get($this->url)->assertOk();
        }

        $this->post($this->url)->assertStatus(429);

        expect(($this->annSetting)())->toBeTrue();
    });
});
