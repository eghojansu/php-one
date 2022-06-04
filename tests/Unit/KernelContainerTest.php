<?php

namespace Tests\Unit;

use Ekok\One\Kernel;
use Tests\Feature\Arr;
use Tests\Feature\Bag;
use Tests\Feature\Ent;
use Tests\Feature\Obj;

class KernelContainerTest extends TestCase
{
    protected $kernelize = true;

    public function testContainerBasic()
    {
        $newKernel = $this->kernel->make(Kernel::class, array(
            'foo' => 'bar',
        ));

        $this->assertSame($this->kernel, $this->kernel->make(Kernel::class));
        $this->assertInstanceOf(Kernel::class, $newKernel);
        $this->assertNotSame($this->kernel, $newKernel);
        $this->assertSame('bar', $newKernel['foo']);

        $this->kernel->singleton(Ent::class);
        $this->kernel->singleton('arr_instance', Arr::class);

        $ent1 = $this->kernel->make(Ent::class);
        $ent2 = $this->kernel->make(Ent::class);

        $this->assertInstanceOf(Ent::class, $ent1);
        $this->assertSame($ent1, $ent2);

        $arr1 = $this->kernel->make('arr_instance');
        $arr2 = $this->kernel->make('arr_instance');

        $this->assertInstanceOf(Arr::class, $arr1);
        $this->assertSame($arr1, $arr2);

        $bag1 = $this->kernel->make(Bag::class);
        $bag2 = $this->kernel->make(Bag::class);

        $this->assertInstanceOf(Bag::class, $bag1);
        $this->assertInstanceOf(Bag::class, $bag2);
        $this->assertNotSame($bag1, $bag2);

        $obj1 = new Obj();
        $obj2 = $this->kernel->singleton(Obj::class, $obj1)->make(Obj::class);

        $this->assertInstanceOf(Obj::class, $obj1);
        $this->assertSame($obj1, $obj2);

        $this->kernel->bind('today', static fn () => new \DateTime());

        $dt1 = $this->kernel->make('today');
        $dt2 = $this->kernel->make('today');

        $this->assertInstanceOf(\DateTime::class, $dt1);
        $this->assertInstanceOf(\DateTime::class, $dt2);
        $this->assertNotSame($dt1, $dt2);
    }

    public function testMakeUnInstantiable()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('Cannot instantiate: DateTimeInterface');

        $this->kernel->make(\DateTimeInterface::class);
    }

    public function testCallArgumentsResolving()
    {
        $format = 'Y-m-d';
        $ent = new Ent();
        $call = static fn (Ent $ent, string $value) => $ent->setFoo($value);
        $any = static fn (string|int $value) => gettype($value);
        $greedy = static fn (int $int, string|null $str, ...$any) => func_get_args();
        $count = static fn ($value) => array($value, func_num_args());

        $this->kernel->singleton(Ent::class, $ent);

        $this->assertSame($ent, $this->kernel->callArguments($call, array('bar')));
        $this->assertSame('bar', $ent->getFoo());

        $this->assertSame($ent, $this->kernel->callArguments($call, array('baz')));
        $this->assertSame('baz', $ent->getFoo());

        $this->assertSame(date($format), $this->kernel->call('DateTime@format', $format));

        $this->assertSame('string', $this->kernel->call($any, 'foo'));
        $this->assertSame('integer', $this->kernel->call($any, 24));
        $this->assertSame(array('foo', 1), $this->kernel->call($count, 'foo'));
        $this->assertSame(array(1, null), $this->kernel->call($greedy, 1));
        $this->assertSame(array(1, 'foo', 'bar'), $this->kernel->call($greedy, 1, 'foo', 'bar'));
    }

    public function testCallArgumentsResolvingWithLessArguments()
    {
        $this->expectException('ArgumentCountError');
        $this->expectExceptionMessage('Too few arguments to function');

        $this->kernel->call(static fn (int $a, int $b) => null);
    }

    public function testCallExpression()
    {
        $this->kernel->singleton(Ent::class, (new Ent())->setFoo('bar'));

        $format = 'Y-m-d';
        $trim = $this->kernel->callEnsure('trim');
        $getFoo = $this->kernel->callEnsure('Tests\\Feature\\Ent@getFoo');
        $today = $this->kernel->callEnsure('DateTime:createFromFormat');

        $this->assertSame('foo', $trim(' foo '));
        $this->assertSame('bar', $getFoo());
        $this->assertSame(date($format), $today($format, date($format))->format($format));
    }

    public function testCallInvalidExpression()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('Invalid call: DateTime:xyz');

        $this->kernel->call('DateTime:xyz');
    }
}
