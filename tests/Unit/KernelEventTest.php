<?php

namespace Tests\Unit;

use Ekok\One\Event;

class KernelEventTest extends TestCase
{
    protected $kernelize = true;

    public function testEvents()
    {
        $this->kernel->on('foo', static fn (Event $event) => $event->data[] = 1);
        $this->kernel->one('foo', static fn (Event $event) => $event->stopPropagation()->data[] = 2);
        $this->kernel->on('foo', static fn (Event $event) => $event->data[] = 3, 0, 'third');
        $this->kernel->on('foo', static fn (Event $event) => $event->data[] = 4);
        $this->kernel->on('bar', static fn (Event $event) => $event->bar = true);

        $this->kernel->dispatch($event = new Event('foo'));
        $this->assertTrue($event->isPropagationStopped());
        $this->assertSame(array(3, 1, 2), $event->data);

        $this->kernel->dispatch($event = new Event(), 'foo', true);
        $this->assertFalse($event->isPropagationStopped());
        $this->assertSame(array(3, 1, 4), $event->data);

        $this->kernel->dispatch($event = new Event('foo'));
        $this->assertFalse($event->isPropagationStopped());
        $this->assertObjectNotHasAttribute('data', $event);

        $this->kernel->dispatch($event = (new Event('bar'))->stopPropagation());
        $this->assertTrue($event->isPropagationStopped());
        $this->assertObjectNotHasAttribute('bar', $event);
    }
}
