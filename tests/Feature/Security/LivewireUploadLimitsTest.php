<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;

beforeEach(function () {
    Storage::fake('tmp-for-tests');

    $this->uploadMessage = fn (int $minutes): string => 'Too many uploads in a short time. Please try again in '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').'.';

    $this->postUpload = function (?User $user, string $name = 'logo.png') {
        $url = (new GenerateSignedUploadUrl)->forLocal();
        $request = $user === null ? $this : $this->actingAs($user);

        return $request->post($url, ['files' => [UploadedFile::fake()->create($name, 10)]], ['Accept' => 'application/json']);
    };

    $this->uploadedFiles = fn (): array => Storage::disk('tmp-for-tests')->allFiles('livewire-tmp');
});

it('lets a signed-in user upload a temporary file', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    ($this->postUpload)($user)->assertOk()->assertJsonCount(1, 'paths');

    expect(($this->uploadedFiles)())->toHaveCount(2);
});

it('refuses a guest holding a valid signed url and writes nothing', function () {
    ($this->postUpload)(null, 'x.bin')->assertUnauthorized();

    expect(($this->uploadedFiles)())->toBeEmpty();
});

it('redirects a guest posting without the json header to the login page and writes nothing', function () {
    $url = (new GenerateSignedUploadUrl)->forLocal();

    $this->post($url, ['files' => [UploadedFile::fake()->create('x.bin', 10)]])->assertRedirect(route('login'));

    expect(($this->uploadedFiles)())->toBeEmpty();
});

it('refuses the eleventh upload in a minute with a message on the field', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    foreach (range(1, 10) as $ignored) {
        ($this->postUpload)($user)->assertOk();
    }

    $response = ($this->postUpload)($user)->assertStatus(422);

    expect($response->json('errors')['files.0'][0])->toBe(($this->uploadMessage)(1))
        ->and($response->json('message'))->toBe(($this->uploadMessage)(1))
        ->and($response->headers->has('Retry-After'))->toBeTrue()
        ->and(($this->uploadedFiles)())->toHaveCount(20);
});

it('gives the minute budget back after a minute', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'UTC'));

    foreach (range(1, 10) as $ignored) {
        ($this->postUpload)($user)->assertOk();
    }

    ($this->postUpload)($user)->assertStatus(422);

    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:01:01', 'UTC'));

    ($this->postUpload)($user)->assertOk();
});

it('gives each user their own budget', function () {
    $ana = User::factory()->create(['email' => 'ana@example.com']);
    $ben = User::factory()->create(['email' => 'ben@example.com']);

    foreach (range(1, 10) as $ignored) {
        ($this->postUpload)($ana)->assertOk();
    }

    ($this->postUpload)($ana)->assertStatus(422);
    ($this->postUpload)($ben)->assertOk();
});

it('refuses an upload over the hourly budget and says how long to wait', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'UTC'));

    foreach (range(1, 3) as $round) {
        foreach (range(1, 10) as $ignored) {
            ($this->postUpload)($user)->assertOk();
        }

        $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'UTC')->addMinutes($round * 2));
    }

    $response = ($this->postUpload)($user)->assertStatus(422);

    expect($response->json('errors')['files.0'][0])->toStartWith('Too many uploads in a short time. Please try again in ')
        ->and($response->json('errors')['files.0'][0])->toEndWith(' minutes.')
        ->and($response->json('errors')['files.0'][0])->not->toContain('1 minutes');
});

it('defines the minute, hour and day budgets per user', function () {
    $user = User::factory()->create();
    $request = Request::create('/livewire/upload-file', 'POST');
    $request->setUserResolver(fn () => $user);

    $limits = RateLimiter::limiter('livewire-uploads')($request);

    expect($limits)->toHaveCount(3)
        ->and(collect($limits)->pluck('maxAttempts')->all())->toBe([10, 30, 100])
        ->and(collect($limits)->pluck('decaySeconds')->all())->toBe([60, 3600, 86400])
        ->and(collect($limits)->pluck('key')->all())->toBe(['minute|'.$user->id, 'hour|'.$user->id, 'day|'.$user->id]);
});

it('words the refusal in whole minutes from the retry-after header', function (int $seconds, string $expected) {
    $user = User::factory()->create();
    $request = Request::create('/livewire/upload-file', 'POST');
    $request->setUserResolver(fn () => $user);

    foreach (RateLimiter::limiter('livewire-uploads')($request) as $limit) {
        $json = ($limit->responseCallback)($request, ['Retry-After' => $seconds]);

        expect($json->getStatusCode())->toBe(422)
            ->and($json->getData(true)['errors']['files.0'])->toBe([$expected])
            ->and($json->getData(true)['message'])->toBe($expected)
            ->and($json->headers->get('Retry-After'))->toBe((string) $seconds);
    }
})->with([
    'no wait left' => [0, 'Too many uploads in a short time. Please try again in 1 minute.'],
    'under a minute' => [45, 'Too many uploads in a short time. Please try again in 1 minute.'],
    'two minutes rounded up' => [61, 'Too many uploads in a short time. Please try again in 2 minutes.'],
    'an hour' => [3600, 'Too many uploads in a short time. Please try again in 60 minutes.'],
]);
