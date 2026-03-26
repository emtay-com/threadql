<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\HandleSlackRetries;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class HandleSlackRetriesTest extends TestCase
{
    private HandleSlackRetries $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new HandleSlackRetries;
    }

    public function test_it_passes_through_non_retry_requests(): void
    {
        $request = Request::create('/slack/events', 'POST', [
            'event_id' => 'Ev123',
        ]);

        $response = $this->middleware->handle($request, function () {
            return new Response('next', 200);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('next', $response->getContent());
    }

    public function test_it_short_circuits_retry_requests(): void
    {
        $request = Request::create('/slack/events', 'POST', [
            'event_id' => 'Ev123',
        ]);
        $request->headers->set('X-Slack-Retry-Num', '1');
        $request->headers->set('X-Slack-Retry-Reason', 'http_timeout');

        $nextCalled = false;
        $response = $this->middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return new Response('should not reach', 200);
        });

        $this->assertFalse($nextCalled, 'Next middleware should not be called for retries');
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"ok":true}', $response->getContent());
    }

    public function test_it_short_circuits_second_retry(): void
    {
        $request = Request::create('/slack/events', 'POST', [
            'event_id' => 'Ev123',
        ]);
        $request->headers->set('X-Slack-Retry-Num', '2');

        $nextCalled = false;
        $response = $this->middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return new Response('should not reach', 200);
        });

        $this->assertFalse($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());
    }
}
