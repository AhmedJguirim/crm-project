<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;

/**
 * @return array<string, int>
 */
function realSecurityLogSnapshot(): array
{
    clearstatcache();

    $snapshot = [];

    foreach (glob(storage_path('logs/security*.log')) ?: [] as $path) {
        $snapshot[$path] = (int) filesize($path);
    }

    ksort($snapshot);

    return $snapshot;
}

test('the default security channel during a test is in memory', function () {
    expect(config('logging.channels.security'))->toBe(['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']);
    expect(Log::channel('security')->getLogger()->getHandlers()[0])->toBeInstanceOf(TestHandler::class);
});

test('a security line written without faking lands in memory, not in storage/logs', function () {
    $before = realSecurityLogSnapshot();
    $user = User::factory()->onboardingCompleted()->create(['email' => 'isolation@example.com']);

    SecurityLog::info('auth.login', $user);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'isolation@example.com', 'password' => 'wrong-password'])
        ->call('authenticate');

    expect(realSecurityLogSnapshot())->toBe($before);

    $handler = Log::channel('security')->getLogger()->getHandlers()[0];
    $events = array_map(fn ($record): string => $record->message, $handler->getRecords());

    expect($events)->toContain('auth.login')->toContain('auth.login_failed');
});
