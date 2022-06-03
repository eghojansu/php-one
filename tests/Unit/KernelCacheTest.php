<?php

namespace Tests\Unit;

class KernelCacheTest extends TestCase
{
    protected $kernelize = true;

    /** @dataProvider cacheSetProvider */
    public function testCacheSet($ref, $driver, $set)
    {
        if ($ref) {
            $tmp = $this->kernel['TMP'];
            $ref = str_replace('{tmp}', $tmp, $ref);
        }

        $this->kernel['CACHE'] = $set;

        $this->assertSame($driver, $this->kernel['CACHE_DRIVER']);
        $this->assertSame($ref, $this->kernel['CACHE_REF']);
    }

    public function cacheSetProvider()
    {
        return array(
            'default' => array(
                '{tmp}/cache',
                'folder',
                true,
            ),
            'folder' => array(
                'foo',
                'folder',
                'folder=foo',
            ),
            'folder with dir keyword and extra spaces' => array(
                'foo',
                'folder',
                'dir = foo',
            ),
            'disabled' => array(null, null, null),
            'unsupported driver' => array(
                null,
                null,
                'unsupported',
            )
        );
    }

    public function testFolderDriver()
    {
        $data = range(1, 5);

        $this->kernel['CACHE'] = true;

        $this->assertFalse($this->kernel->cacheHas('foo'));

        $this->kernel->cacheSet('foo', $data, 0, $saved);
        $this->assertTrue($saved);
        $this->assertSame($data, $this->kernel->cacheGet('foo', $ref));
        $this->assertTrue($ref['exists']);
        $this->assertFalse($ref['expired']);

        $this->kernel->cacheRemove('foo', $removed);
        $this->assertTrue($removed);
        $this->assertFalse($this->kernel->cacheHas('foo'));

        $this->kernel->cacheSet('foo', 'bar', $saved);
        $this->kernel->cacheClear(null, null, $removed);
        $this->assertTrue($saved);
        $this->assertSame(1, $removed);

        // set expired cache
        $this->kernel->cacheSet('foo', 'bar', -1, $saved);
        $this->assertTrue($saved);
        $this->assertNull($this->kernel->cacheGet('foo', $ref));
        $this->assertTrue($ref['exists']);
        $this->assertTrue($ref['expired']);
    }

    public function testUnsupportedDriver()
    {
        $this->kernel['CACHE'] = 'unsupported';

        $this->assertFalse($this->kernel->cacheHas('foo'));

        $this->kernel->cacheSet('foo', 'bar', 0, $saved);
        $this->assertFalse($saved);
        $this->assertNull($this->kernel->cacheGet('foo', $ref));
        $this->assertFalse($ref['exists']);
        $this->assertFalse($ref['expired']);

        $this->kernel->cacheRemove('foo', $removed);
        $this->assertFalse($removed);
        $this->assertFalse($this->kernel->cacheHas('foo'));

        $this->kernel->cacheSet('foo', 'bar');
        $this->kernel->cacheClear(null, null, $removed);
        $this->assertSame(0, $removed);
    }
}
