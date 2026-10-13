<?php

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Mail\SecuredMarkdown;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDailyDigestNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\EncodedHtmlString;

beforeEach(function () {
    $this->acme = Organization::factory()->create(['name' => 'Acme', 'timezone' => 'UTC']);
    $this->alice = User::factory()->create(['email' => 'alice@example.com']);
});

function mailInjectionTask(Organization $organization, string $title): Task
{
    return Task::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => User::factory()->create()->id,
        'title' => $title,
        'due_at' => Carbon::parse('2026-10-12 14:30:00', 'UTC'),
    ]);
}

test('the invitation mail shows names as typed', function () {
    $this->acme->update(['name' => 'Acme](https://evil.example/login) [Accept here']);
    $inviter = User::factory()->create(['name' => '[Boss](https://evil.example/u) ![i](https://evil.example/p.png)']);
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->acme->id,
        'invited_by' => $inviter->id,
        'role' => OrganizationRole::Member,
    ]);

    $html = (new OrganizationInvitationMail($invite))->render();

    expect($html)
        ->not->toContain('href="https://evil.example')
        ->not->toContain('src="https://evil.example')
        ->toContain('Acme](https://evil.example/login) [Accept here')
        ->toContain('[Boss](https://evil.example/u)')
        ->toContain('href="'.OrganizationInvite::acceptUrl($invite->token).'"');
});

test('task reminder mails show the title as typed', function (string $notification, string $line) {
    $task = mailInjectionTask($this->acme, '[Pay invoice](https://evil.example)');

    $html = (string) (new $notification($task, $this->acme, 'key-1'))->toMail($this->alice)->render();

    expect($html)
        ->not->toContain('href="https://evil.example')
        ->toContain($line)
        ->toContain('href="'.url("/admin/{$this->acme->slug}/tasks/{$task->id}/edit").'"');
})->with([
    'overdue' => [TaskOverdueNotification::class, 'Your task "[Pay invoice](https://evil.example)" is overdue.'],
    'due soon' => [TaskDueSoonNotification::class, 'Your task "[Pay invoice](https://evil.example)" is due tomorrow.'],
]);

test('the daily digest shows organization and task names as typed', function () {
    $this->acme->update(['name' => '[Org](https://evil.example/o)']);
    $task = mailInjectionTask($this->acme, '[Pay invoice](https://evil.example)');

    $html = (string) (new TaskDailyDigestNotification($this->acme, collect([$task]), collect([$task])))->toMail($this->alice)->render();

    expect($html)
        ->not->toContain('href="https://evil.example')
        ->toContain('Here is your daily task summary for [Org](https://evil.example/o).')
        ->toContain('OVERDUE: [Pay invoice](https://evil.example)');
});

test('ordinary titles still read normally', function (string $title, string $shown) {
    $task = mailInjectionTask($this->acme, $title);

    $html = (string) (new TaskOverdueNotification($task, $this->acme, 'key-1'))->toMail($this->alice)->render();

    expect($html)->toContain($shown);
})->with([
    'square brackets' => ['[urgent] Call Bob', 'Your task "[urgent] Call Bob" is overdue.'],
    'ampersand and tags' => ['Tom & Jerry <b>x</b>', 'Tom &amp; Jerry &lt;b&gt;x&lt;/b&gt;'],
]);

/**
 * Renders one of the four mails with the payload in every user value it echoes (inviter and organization name, task title).
 */
function mailInjectionRender(string $mail, string $payload, Organization $organization, User $recipient): string
{
    if ($mail === 'invitation') {
        $organization->update(['name' => $payload]);
        $invite = OrganizationInvite::factory()->create([
            'organization_id' => $organization->id,
            'invited_by' => User::factory()->create(['name' => $payload])->id,
            'role' => OrganizationRole::Member,
        ]);

        return (new OrganizationInvitationMail($invite))->render();
    }

    $task = mailInjectionTask($organization, $payload);

    if ($mail === 'digest') {
        $organization->update(['name' => $payload]);

        return (string) (new TaskDailyDigestNotification($organization, collect([$task]), collect([$task])))->toMail($recipient)->render();
    }

    $notification = $mail === 'overdue' ? TaskOverdueNotification::class : TaskDueSoonNotification::class;

    return (string) (new $notification($task, $organization, 'key-1'))->toMail($recipient)->render();
}

test('a backslash or an image mark in a value can not bring the link back', function (string $mail, string $payload) {
    $html = mailInjectionRender($mail, $payload, $this->acme, $this->alice);

    expect($html)
        ->not->toMatch('/<(a|img)\b[^>]*\b(href|src)="https:\/\/evil\.example/')
        ->not->toContain('&#92;')
        ->toContain('evil.example');
})->with(function () {
    $payloads = [
        'backslash link' => '\[x](https://evil.example/a)',
        'backslash image' => '!\[i](https://evil.example/p.png)',
        'double backslash link' => '\\\\[x](https://evil.example/a)',
        'plain link' => '[x](https://evil.example/a)',
        'plain image' => '![i](https://evil.example/p.png)',
        'autolink' => '<https://evil.example/auto>',
        'raw html anchor' => '<a href="https://evil.example/h">x</a>',
        'backslash before angle autolink' => '\<https://evil.example/auto>',
    ];

    foreach (['invitation', 'overdue', 'duesoon', 'digest'] as $mail) {
        foreach ($payloads as $label => $payload) {
            yield "{$mail} {$label}" => [$mail, $payload];
        }
    }
});

test('the action button of a reminder keeps its url while the title carries a backslash payload', function () {
    $task = mailInjectionTask($this->acme, '\[x](https://evil.example/a)');

    $html = (string) (new TaskOverdueNotification($task, $this->acme, 'key-1'))->toMail($this->alice)->render();

    expect($html)
        ->toContain('Your task "[x](https://evil.example/a)" is overdue.')
        ->toContain('href="'.url("/admin/{$this->acme->slug}/tasks/{$task->id}/edit").'"');
});

test('the plain text part keeps the title as typed', function () {
    $task = mailInjectionTask($this->acme, '\[x](https://evil.example/a)');
    $message = (new TaskOverdueNotification($task, $this->acme, 'key-1'))->toMail($this->alice);

    $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());

    expect($text)
        ->toContain('Your task "\[x](https://evil.example/a)" is overdue.')
        ->not->toContain('&#92;');
});

test('the mail renderer is the secured one and keeps the configured theme', function () {
    config(['mail.markdown.theme' => 'custom-theme']);
    app()->forgetInstance(Markdown::class);

    expect(app(Markdown::class))->toBeInstanceOf(SecuredMarkdown::class)
        ->and(app(Markdown::class)->getTheme())->toBe('custom-theme');
});

test('switching the framework secured encoding on does not reopen the backslash hole', function () {
    Markdown::withSecuredEncoding();

    $html = mailInjectionRender('overdue', '\\[x](https://evil.example/a)', $this->acme, $this->alice);

    expect($html)->not->toContain('href="https://evil.example');
});

test('the secured renderer encodes like the framework secured encoder, so a framework change shows up here', function () {
    $payload = 'Tom & "Jerry" \'c\' <b>x</b> > [e](f) ![g](h) * _ ` # | ~ &amp; &#91; é 😀';
    $this->acme->update(['name' => $payload]);
    $task = mailInjectionTask($this->acme, $payload);
    $message = (new TaskDailyDigestNotification($this->acme, collect([$task]), collect([$task])))->toMail($this->alice);
    $options = ['theme' => config('mail.markdown.theme')];

    $directory = sys_get_temp_dir().'/mail-encoder-parity-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/mail-encoder-parity.blade.php', '@php $sink["out"] = (new \Illuminate\Support\EncodedHtmlString($value))->toHtml(); @endphp<p>x</p>');
    view()->addLocation($directory);

    try {
        $encode = function (Markdown $markdown) use ($payload): string {
            $sink = new ArrayObject;
            $markdown->render('mail-encoder-parity', ['value' => $payload, 'sink' => $sink]);

            return $sink['out'];
        };

        $securedEncoded = $encode(new SecuredMarkdown(app('view'), $options));
        $securedHtml = (string) (new SecuredMarkdown(app('view'), $options))->render($message->markdown, $message->data());

        Markdown::withSecuredEncoding();
        $frameworkEncoded = $encode(new Markdown(app('view'), $options));
        $frameworkHtml = (string) (new Markdown(app('view'), $options))->render($message->markdown, $message->data());
    } finally {
        unlink($directory.'/mail-encoder-parity.blade.php');
        rmdir($directory);
    }

    $failure = "The framework's secured encoder changed: update the replacements in App\\Mail\\SecuredMarkdown.";

    expect($securedEncoded)->toContain('\[e](f)')
        ->and($securedEncoded)->toBe($frameworkEncoded, $failure)
        ->and($securedHtml)->toContain('daily task summary')
        ->and($securedHtml)->toBe($frameworkHtml, $failure);
});

test('the secured renderer leaves nothing behind for the next render', function () {
    mailInjectionRender('overdue', '\[x](https://evil.example/a)', $this->acme, $this->alice);

    expect((string) Markdown::parse('[a](https://ok.example)'))->toContain('href="https://ok.example"')
        ->and(EncodedHtmlString::convert('a & [b]'))->toBe('a &amp; [b]');
});
