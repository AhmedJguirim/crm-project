<?php

use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00:00', 'UTC'));

    $make = fn (string $email, string $createdAt, ?string $verifiedAt = null): User => User::factory()->create(['email' => $email])
        ->forceFill(['created_at' => $createdAt, 'email_verified_at' => $verifiedAt])->save() ? User::where('email', $email)->first() : throw new RuntimeException;

    $this->old = $make('old@example.com', '2026-11-10 08:00:00');
    $this->recent = $make('new@example.com', '2026-11-21 08:00:00');
    $this->done = $make('done@example.com', '2026-11-01 08:00:00', '2026-11-02 08:00:00');
});

test('users created before the cutoff are marked verified', function () {
    $this->artisan('users:mark-existing-verified', ['--created-before' => '2026-11-20 00:00:00'])
        ->expectsOutput('Marked 1 users created before 2026-11-20 00:00:00 UTC as verified.')
        ->assertExitCode(0);

    expect($this->old->fresh()->email_verified_at->toDateTimeString())->toBe('2026-11-22 10:00:00')
        ->and($this->recent->fresh()->email_verified_at)->toBeNull()
        ->and($this->done->fresh()->email_verified_at->toDateTimeString())->toBe('2026-11-02 08:00:00');
});

test('a dry run writes nothing', function () {
    $this->artisan('users:mark-existing-verified', ['--created-before' => '2026-11-20 00:00:00', '--dry-run' => true])
        ->expectsOutput('1 users created before 2026-11-20 00:00:00 UTC would be marked verified.')
        ->assertExitCode(0);

    expect($this->old->fresh()->email_verified_at)->toBeNull();
});

test('a missing or invalid cutoff writes nothing', function (array $options) {
    $this->artisan('users:mark-existing-verified', $options)
        ->expectsOutput('Give --created-before as a UTC date-time, for example --created-before="2026-11-20 00:00:00".')
        ->assertExitCode(1);

    expect($this->old->fresh()->email_verified_at)->toBeNull()->and($this->recent->fresh()->email_verified_at)->toBeNull();
})->with([
    'none' => [[]],
    'date only' => [['--created-before' => '2026-11-20']],
    'rolled-over month' => [['--created-before' => '2026-13-01 00:00:00']],
    'junk' => [['--created-before' => 'abc']],
    'with trailing text' => [['--created-before' => '2026-11-20 00:00:00; drop']],
]);
