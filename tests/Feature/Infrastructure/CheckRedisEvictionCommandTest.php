<?php

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    config([
        'horizon.use' => 'default',
        'queue.connections.redis.connection' => 'default',
        'queue.connections.redis-long.connection' => 'default',
        'cache.default' => 'redis',
        'cache.stores.redis.connection' => 'cache',
        'cache.stores.redis.lock_connection' => 'default',
        'session.driver' => 'database',
        'session.connection' => null,
    ]);
});

/**
 * @param  mixed  $answer  an array/false/value to return, or a Throwable to throw
 */
function redisEvictionConnection(mixed $answer, int $times = 1): Connection
{
    $connection = Mockery::mock(Connection::class);
    $expectation = $connection->shouldReceive('command')->with('config', ['GET', 'maxmemory-policy'])->times($times);

    if ($answer instanceof Throwable) {
        $expectation->andThrow($answer);
    } else {
        $expectation->andReturn($answer);
    }

    return $connection;
}

function redisEvictionPolicy(string $policy): array
{
    return ['maxmemory-policy' => $policy];
}

function redisEvictionRun(): array
{
    $code = Artisan::call('security:check-redis');

    return [$code, Artisan::output()];
}

test('both connections using noeviction pass and a shared connection is asked once', function () {
    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));
    Redis::shouldReceive('connection')->with('cache')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));

    [$code, $output] = redisEvictionRun();

    expect($code)->toBe(0)
        ->and($output)->toContain('Redis connection "default": maxmemory-policy is noeviction.')
        ->and($output)->toContain('Redis connection "cache": maxmemory-policy is noeviction.');
});

test('an eviction policy fails the check', function (string $policy) {
    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));
    Redis::shouldReceive('connection')->with('cache')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy($policy)));

    [$code, $output] = redisEvictionRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('Redis connection "cache": maxmemory-policy is '.$policy.'. When Redis is full it can drop queued jobs, login lockouts and rate limits. Set maxmemory-policy noeviction in redis.conf and run CONFIG SET maxmemory-policy noeviction.')
        ->and($output)->toContain('Redis connection "default": maxmemory-policy is noeviction.');
})->with(['allkeys-lru', 'volatile-ttl']);

test('the policy is compared case-insensitively and trimmed', function () {
    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('NoEviction')));
    Redis::shouldReceive('connection')->with('cache')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy(' noeviction ')));

    expect(redisEvictionRun()[0])->toBe(0);
});

test('the cache connection is not checked when the cache is not on Redis', function () {
    config(['cache.default' => 'database']);

    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));
    Redis::shouldNotReceive('connection')->with('cache');

    [$code, $output] = redisEvictionRun();

    expect($code)->toBe(0)
        ->and($output)->not->toContain('"cache"');
});

test('the cache lock connection is checked', function () {
    config(['cache.stores.redis.lock_connection' => 'locks']);

    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));
    Redis::shouldReceive('connection')->with('cache')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));
    Redis::shouldReceive('connection')->with('locks')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('allkeys-lru')));

    [$code, $output] = redisEvictionRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('Redis connection "locks": maxmemory-policy is allkeys-lru.');
});

test('the cache lock connection is not checked when the cache is not on Redis', function () {
    config(['cache.default' => 'database', 'cache.stores.redis.lock_connection' => 'locks']);

    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));
    Redis::shouldNotReceive('connection')->with('locks');

    [$code, $output] = redisEvictionRun();

    expect($code)->toBe(0)
        ->and($output)->not->toContain('"locks"');
});

test('a Redis session connection is checked', function () {
    config(['cache.default' => 'database', 'session.driver' => 'redis', 'session.connection' => 'sessions']);

    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));
    Redis::shouldReceive('connection')->with('sessions')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('allkeys-random')));

    [$code, $output] = redisEvictionRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('Redis connection "sessions": maxmemory-policy is allkeys-random.');
});

test('a Redis session without a named connection falls back to default', function () {
    config([
        'horizon.use' => 'queues',
        'queue.connections.redis.connection' => 'queues',
        'queue.connections.redis-long.connection' => 'queues',
        'cache.default' => 'database',
        'session.driver' => 'redis',
        'session.connection' => null,
    ]);

    Redis::shouldReceive('connection')->with('queues')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));
    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('allkeys-lru')));

    expect(redisEvictionRun()[0])->toBe(1);
});

test('empty or missing connection names are skipped', function () {
    config([
        'horizon.use' => null,
        'queue.connections.redis.connection' => '',
        'queue.connections.redis-long.connection' => 'default',
        'cache.default' => 'database',
    ]);

    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));

    expect(redisEvictionRun()[0])->toBe(0);
});

test('an unreadable policy only warns', function (mixed $answer) {
    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));
    Redis::shouldReceive('connection')->with('cache')->once()->andReturn(redisEvictionConnection($answer));

    [$code, $output] = redisEvictionRun();

    expect($code)->toBe(0)
        ->and($output)->toContain('Redis connection "cache": could not read maxmemory-policy (the host may disable CONFIG). Check by hand that it is noeviction.');
})->with([
    'throws' => fn () => new RedisException("ERR unknown command 'CONFIG'"),
    'false' => false,
    'empty array' => [[]],
    'blank policy' => [['maxmemory-policy' => '  ']],
    'non-string policy' => [['maxmemory-policy' => ['noeviction']]],
]);

test('a failing connection never leaks its error text', function () {
    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));
    Redis::shouldReceive('connection')->with('cache')->once()->andReturn(redisEvictionConnection(new RedisException('connection refused to redis.internal with s3cr3t-pass')));

    [, $output] = redisEvictionRun();

    expect($output)->not->toContain('s3cr3t-pass')
        ->and($output)->not->toContain('redis.internal');
});

test('an unknown connection name warns instead of crashing', function () {
    config(['cache.stores.redis.connection' => 'does-not-exist']);

    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('noeviction')));
    Redis::shouldReceive('connection')->with('does-not-exist')->once()->andThrow(new InvalidArgumentException('Redis connection [does-not-exist] not configured.'));

    [$code, $output] = redisEvictionRun();

    expect($code)->toBe(0)
        ->and($output)->toContain('Redis connection "does-not-exist": could not read maxmemory-policy')
        ->and($output)->not->toContain('not configured');
});

test('one bad connection still fails when another one is unreadable', function () {
    Redis::shouldReceive('connection')->with('default')->once()->andReturn(redisEvictionConnection(redisEvictionPolicy('allkeys-lru')));
    Redis::shouldReceive('connection')->with('cache')->once()->andReturn(redisEvictionConnection(new RedisException('boom')));

    [$code, $output] = redisEvictionRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('Redis connection "default": maxmemory-policy is allkeys-lru.')
        ->and($output)->toContain('Redis connection "cache": could not read');
});

test('the command never writes to Redis', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('command')->with('config', ['GET', 'maxmemory-policy'])->andReturn(redisEvictionPolicy('noeviction'));
    $connection->shouldNotReceive('__call');
    Redis::shouldReceive('connection')->andReturn($connection);

    expect(redisEvictionRun()[0])->toBe(0);
});
