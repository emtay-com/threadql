<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Support;

use App\Jobs\Support\QueryCacheKeyManager;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class QueryCacheKeyManagerTest extends TestCase
{
    private QueryCacheKeyManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new QueryCacheKeyManager(Cache::store());
    }

    public function test_query_key_format(): void
    {
        $this->assertEquals('query-active:10:42', $this->manager->queryKey(10, 42));
    }

    public function test_thread_key_format(): void
    {
        $this->assertEquals('thread-active-query:10', $this->manager->threadKey(10));
    }

    public function test_mark_active_sets_both_keys(): void
    {
        $this->manager->markActive(10, 42);

        $this->assertTrue(Cache::has('query-active:10:42'));
        $this->assertEquals(42, Cache::get('thread-active-query:10'));
    }

    public function test_mark_inactive_removes_both_keys(): void
    {
        $this->manager->markActive(10, 42);
        $this->manager->markInactive(10, 42);

        $this->assertFalse(Cache::has('query-active:10:42'));
        $this->assertFalse(Cache::has('thread-active-query:10'));
    }

    public function test_is_query_active_returns_true_when_active(): void
    {
        $this->manager->markActive(10, 42);

        $this->assertTrue($this->manager->isQueryActive(10, 42));
    }

    public function test_is_query_active_returns_false_when_inactive(): void
    {
        $this->assertFalse($this->manager->isQueryActive(10, 42));
    }

    public function test_has_active_query_in_thread_returns_true_when_active(): void
    {
        $this->manager->markActive(10, 42);

        $this->assertTrue($this->manager->hasActiveQueryInThread(10));
    }

    public function test_has_active_query_in_thread_returns_false_when_inactive(): void
    {
        $this->assertFalse($this->manager->hasActiveQueryInThread(10));
    }

    public function test_mark_active_uses_configured_ttl(): void
    {
        config([
            'threadql.query_active_ttl' => 60,
        ]);

        $this->manager->markActive(10, 42);

        // Keys should exist immediately after being set
        $this->assertTrue($this->manager->isQueryActive(10, 42));
        $this->assertTrue($this->manager->hasActiveQueryInThread(10));
    }
}
