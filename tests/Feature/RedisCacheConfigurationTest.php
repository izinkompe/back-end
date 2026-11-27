<?php

namespace Tests\Feature;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Predis\Client;
use Tests\TestCase;

class RedisCacheConfigurationTest extends TestCase
{
    public function test_redis_cache_uses_predis_with_the_upstash_tls_endpoint(): void
    {
        config([
            'database.redis.client' => 'predis',
            'database.redis.cache.host' => 'tls://example.upstash.io',
            'database.redis.cache.password' => 'test-password',
            'database.redis.cache.port' => 6379,
            'database.redis.cache.database' => 0,
        ]);

        $this->assertInstanceOf(RedisStore::class, Cache::store('redis')->getStore());
        $client = Redis::connection('cache')->client();
        $this->assertInstanceOf(Client::class, $client);
        $parameters = $client->getConnection()->getParameters();
        $this->assertSame('tls', $parameters->scheme);
        $this->assertSame('example.upstash.io', $parameters->host);
        $this->assertSame(6379, $parameters->port);
    }
}
