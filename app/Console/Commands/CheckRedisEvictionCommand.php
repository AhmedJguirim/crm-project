<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Fails when a Redis connection the app uses can evict keys (SEC-74). Queues, Horizon, login lockouts, invitation caps
 * and rate limiters share Redis, so every connection must run with maxmemory-policy noeviction. Read only.
 */
class CheckRedisEvictionCommand extends Command
{
    protected $signature = 'security:check-redis';

    protected $description = 'Fail when a Redis connection the app uses can evict keys (maxmemory-policy is not noeviction)';

    public function handle(): int
    {
        $failed = false;

        foreach ($this->connectionNames() as $name) {
            $policy = $this->readPolicy($name);

            if ($policy === null) {
                $this->warn("Redis connection \"{$name}\": could not read maxmemory-policy (the host may disable CONFIG). Check by hand that it is noeviction.");

                continue;
            }

            if ($policy === 'noeviction') {
                $this->info("Redis connection \"{$name}\": maxmemory-policy is noeviction.");

                continue;
            }

            $failed = true;
            $this->error("Redis connection \"{$name}\": maxmemory-policy is {$policy}. When Redis is full it can drop queued jobs, login lockouts and rate limits. Set maxmemory-policy noeviction in redis.conf and run CONFIG SET maxmemory-policy noeviction.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function connectionNames(): array
    {
        $names = [
            config('horizon.use'),
            config('queue.connections.redis.connection'),
            config('queue.connections.redis-long.connection'),
        ];

        if (config('cache.default') === 'redis') {
            $names[] = config('cache.stores.redis.connection');
            $names[] = config('cache.stores.redis.lock_connection');
        }

        if (config('session.driver') === 'redis') {
            $names[] = config('session.connection') ?? 'default';
        }

        $names = array_filter($names, fn (mixed $name): bool => is_string($name) && $name !== '');

        return array_values(array_unique($names));
    }

    private function readPolicy(string $name): ?string
    {
        try {
            $result = Redis::connection($name)->command('config', ['GET', 'maxmemory-policy']);
        } catch (Throwable) {
            return null;
        }

        $policy = is_array($result) ? ($result['maxmemory-policy'] ?? null) : null;

        if (! is_string($policy) || trim($policy) === '') {
            return null;
        }

        return strtolower(trim($policy));
    }
}
