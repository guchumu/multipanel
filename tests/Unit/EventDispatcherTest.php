<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\EventDispatcher;
use Tests\TestCase;

final class EventDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        EventDispatcher::flush();
    }

    public function testDispatchCallsListeners(): void
    {
        $called = false;
        EventDispatcher::listen('test.event', function ($payload) use (&$called) {
            $called = true;
            return $payload;
        });

        EventDispatcher::dispatch('test.event', 'data');
        $this->assertTrue($called);
    }

    public function testListenersRunByPriority(): void
    {
        $order = [];
        EventDispatcher::listen('order.test', fn () => $order[] = 'low', 1);
        EventDispatcher::listen('order.test', fn () => $order[] = 'high', 100);

        EventDispatcher::dispatch('order.test');
        $this->assertSame(['high', 'low'], $order);
    }
}
