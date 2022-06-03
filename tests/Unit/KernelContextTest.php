<?php

namespace Tests\Unit;

use stdClass;
use Tests\Feature\Arr;
use Tests\Feature\Bag;
use Tests\Feature\Ent;
use Tests\Feature\Obj;

class KernelContextTest extends TestCase
{
    protected $kernelize = true;

    public function testGetContext()
    {
        $this->assertCount(0, $this->kernel->getContext());
    }

    public function testRefBasic()
    {
        $foo = &$this->kernel->ref('foo', false, $ref);

        $this->assertNull($foo);
        $this->assertFalse($ref['found']);
        $this->assertEquals(array('foo'), $ref['parts']);

        // adding non-reference
        $foo = 'bar';

        $this->assertArrayNotHasKey('foo', $this->kernel->getContext());

        // adding reference
        $foo = &$this->kernel->ref('foo', true, $ref);

        $this->assertNull($foo);
        $this->assertFalse($ref['found']);

        // adding reference value
        $foo = 'bar';

        $this->assertArrayHasKey('foo', $this->kernel->getContext());

        // confirm existance
        unset($foo);
        $foo = $this->kernel->ref('foo', false, $ref);

        $this->assertSame('bar', $foo);
        $this->assertTrue($ref['found']);
    }

    public function testRefArray()
    {
        $foo = &$this->kernel->ref('foo.bar', false, $ref);

        $this->assertNull($foo);
        $this->assertFalse($ref['found']);
        $this->assertEquals(array('foo', 'bar'), $ref['parts']);

        // adding non-reference
        $foo = 'bar';

        $this->assertArrayNotHasKey('foo', $this->kernel->getContext());

        // adding reference
        $foo = &$this->kernel->ref('foo.bar', true, $ref);

        $this->assertNull($foo);
        $this->assertFalse($ref['found']);

        // adding reference value
        $foo = 'bar';

        $this->assertArrayHasKey('foo', $this->kernel->getContext());

        // confirm existance
        unset($foo);
        $foo = $this->kernel->ref('foo.bar', false, $ref);

        $this->assertSame('bar', $foo);
        $this->assertTrue($ref['found']);
    }

    public function testRefObject()
    {
        $foo = &$this->kernel->ref('foo.bar', false, $ref);

        $this->assertNull($foo);
        $this->assertFalse($ref['found']);
        $this->assertEquals(array('foo', 'bar'), $ref['parts']);

        // adding non-reference
        $foo = 'bar';

        $this->assertArrayNotHasKey('foo', $this->kernel->getContext());

        // adding reference
        $foo = &$this->kernel->ref('foo', true, $ref);

        $this->assertNull($foo);
        $this->assertFalse($ref['found']);

        // adding reference value
        $foo = new stdClass();
        $foo->bar = 'bar';

        $this->assertArrayHasKey('foo', $this->kernel->getContext());

        // confirm existance
        unset($foo);
        $foo = $this->kernel->ref('foo.bar', false, $ref);

        $this->assertSame('bar', $foo);
        $this->assertTrue($ref['found']);

        // non exists object property set
        $baz = &$this->kernel->ref('foo.baz', true, $ref);
        $baz = 'baz';

        $this->assertSame('baz', $this->kernel->ref('foo.baz'));
        $this->assertFalse($ref['found']);

        // Array object
        $arr = &$this->kernel->ref('arr');
        $arr = new Arr();

        $arrFoo = &$this->kernel->ref('arr.foo');
        $arrFoo = 'bar';

        $this->assertSame('bar', $this->kernel->ref('arr.foo'));

        // Pure object
        $obj = &$this->kernel->ref('obj');
        $obj = new Obj();

        $objFoo = &$this->kernel->ref('obj.foo');
        $objFoo = 'bar';

        $this->assertSame('bar', $this->kernel->ref('obj.foo'));

        // Bag object
        $bag = &$this->kernel->ref('bag');
        $bag = new Bag();

        $bagFoo = &$this->kernel->ref('bag.foo');
        $bagFoo = 'bar';

        $this->assertSame(null, $this->kernel->ref('bag.foo'));

        // Entity object
        $ent = &$this->kernel->ref('ent');
        $ent = new Ent();

        $entFoo = &$this->kernel->ref('ent.foo');
        $entFoo = 'bar';

        $this->assertSame('bar', $this->kernel->ref('ent.foo'));
    }

    public function testUnref()
    {
        $foo = &$this->kernel->ref('foo');
        $foo = 'bar';

        $this->assertSame(array(), $this->kernel->unref('foo'));

        $arr = &$this->kernel->ref('arr');
        $arr = array('foo' => 'bar', 'bar' => true);

        $this->assertSame(array('bar' => true), $this->kernel->unref('arr.foo'));
        $this->assertNull($this->kernel->unref('arr.bar.baz'));

        $arrObj = &$this->kernel->ref('arrObj');
        $arrObj = new Arr();
        $arrObj['foo'] = 'bar';

        $this->assertSame($arrObj, $this->kernel->unref('arrObj.foo'));
        $this->assertNull($arrObj['foo']);

        $obj = &$this->kernel->ref('obj');
        $obj = new Obj();
        $obj->foo = 'bar';

        $this->assertSame($obj, $this->kernel->unref('obj.foo'));
        $this->assertNull($obj->foo);

        $ent = &$this->kernel->ref('ent');
        $ent = new Ent();
        $ent->setFoo('bar');

        $this->assertSame($ent, $this->kernel->unref('ent.foo'));
        $this->assertNull($ent->getFoo());

        $bag = &$this->kernel->ref('bag');
        $bag = new Bag();
        $bag->set('foo', 'bar');

        $this->assertSame($bag, $this->kernel->unref('bag.foo'));
        $this->assertNull($bag->get('bag'));
    }

    public function testBagAccess()
    {
        $this->assertFalse($this->kernel->has('foo'));
        $this->assertSame($this->kernel, $this->kernel->set('foo', 'bar'));
        $this->assertSame('bar', $this->kernel->get('foo'));
        $this->assertSame($this->kernel, $this->kernel->remove('foo'));
        $this->assertFalse($this->kernel->has('foo'));
    }

    public function testArrayAccess()
    {
        $this->assertFalse(isset($this->kernel['foo']));
        $this->kernel['foo'] = 'bar';
        $this->assertSame('bar', $this->kernel['foo']);
        unset($this->kernel['foo']);
        $this->assertFalse(isset($this->kernel['foo']));
    }

    public function testPropertyAccess()
    {
        $this->assertFalse(isset($this->kernel->foo));
        $this->kernel->foo = 'bar';
        $this->assertSame('bar', $this->kernel->foo);
        unset($this->kernel->foo);
        $this->assertFalse(isset($this->kernel->foo));
    }

    public function testMassiveAccess()
    {
        $this->assertFalse($this->kernel->allHas('foo'));
        $this->kernel->allSet(array('foo' => 'bar'));
        $this->kernel->allSet(array('bar' => 'baz'), 'foo_');
        $this->assertSame(array(
            'foo' => 'bar',
            'foo_alias' => 'bar',
            'foo_bar' => 'baz',
        ), $this->kernel->allGet(array('foo', 'foo_alias' => 'foo', 'foo_bar')));
        $this->kernel->allRemove('foo');
        $this->assertSame('baz', $this->kernel->get('foo_bar'));
    }

    public function testArrayTreatment()
    {
        $this->kernel->merge('foo', array('foo' => 'bar'));
        $this->kernel->push('foo', 'baz');
        $this->kernel->unshift('foo', 'qux');

        $pushed = $this->kernel->pop('foo');
        $pushedNull = $this->kernel->pop('pushed');
        $shifted = $this->kernel->shift('foo');
        $shiftedNull = $this->kernel->shift('shifted');

        $this->assertSame('baz', $pushed);
        $this->assertSame('qux', $shifted);
        $this->assertNull($pushedNull);
        $this->assertNull($shiftedNull);
        $this->assertSame(array('foo' => 'bar'), $this->kernel->get('foo'));
    }
}
