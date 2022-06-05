<?php

namespace Tests\Unit;

use Ekok\One\Kernel;

class KernelHelperTest extends TestCase
{
    public function testCreation()
    {
        $kernel = Kernel::create();
        $kernelGlobals = Kernel::createFromGlobals();

        $this->assertInstanceOf(Kernel::class, $kernel);
        $this->assertInstanceOf(Kernel::class, $kernelGlobals);

        $this->assertNotSame($kernel, $kernelGlobals);
    }

    public function testSessionActive()
    {
        $this->assertFalse(Kernel::sessionActive());
    }

    public function testSlash()
    {
        $this->assertSame('/foo/bar', Kernel::slash('\\foo\\bar'));
    }

    public function testProjectDir()
    {
        $this->assertSame(
            Kernel::slash(dirname(__DIR__, 2)),
            Kernel::projectDir(),
        );
    }

    public function testParts()
    {
        $this->assertSame(array(1), Kernel::parts(1));
        $this->assertSame(array('foo'), Kernel::parts('foo'));
        $this->assertSame(array('foo', 'bar'), Kernel::parts('foo.bar'));
        $this->assertSame(array('foo', 'bar.qux'), Kernel::parts('foo.bar\\.qux'));
    }

    public function testSerialize()
    {
        $raw = 'foo';

        $this->assertSame(serialize($raw), Kernel::serialize($raw));
    }

    public function testUnserialize()
    {
        $source = serialize('foo');

        $this->assertSame(unserialize($source), Kernel::unserialize($source));
    }

    public function testSome()
    {
        $this->assertTrue(Kernel::some(range(1,2), static fn ($no) => $no === 1, $found));
        $this->assertSame(array('key' => 0, 'value' => 1), $found);

        $this->assertFalse(Kernel::some(range(1,2), static fn ($no) => $no === 3, $found));
        $this->assertNull($found);
    }

    public function testReduce()
    {
        $this->assertSame(3, Kernel::reduce(range(1, 2), static fn ($a, $b) => $a + $b));
    }

    public function testMap()
    {
        $this->assertSame(array('1.0', '2.1'), Kernel::map(range(1,2), static fn ($a, $b) => $a . '.' . $b));
    }

    public function testRefExec()
    {
        $this->assertSame('trim', Kernel::refExec('getName', 'trim'));
        $this->assertSame('format', Kernel::refExec('getName', 'format', 'DateTime'));
        $this->assertSame('DateTime', Kernel::refExec('getName', null, 'DateTime'));
    }

    public function testCast()
    {
        $this->assertSame(null, Kernel::cast(null));
        $this->assertSame(true, Kernel::cast(' true '));
        $this->assertSame(array(1,2), Kernel::cast(array(1,2)));
        $this->assertSame(1000, Kernel::cast('1000'));
        $this->assertSame(77, Kernel::cast('0x4D')); // hex
        $this->assertSame(77, Kernel::cast('0b01001101')); // binary
        $this->assertSame(77, Kernel::cast('0115')); // octal
    }

    public function testMkdir()
    {
        $dir = $this->tmp('test-mkdir');

        $this->assertTrue(Kernel::mkdir($dir));
        $this->assertTrue(Kernel::mkdir($dir));

        rmdir($dir);
    }

    public function testReadWrite()
    {
        $file = $this->tmp('readwrite.txt');

        // not yet created
        $this->assertNull(Kernel::read($file));

        $this->assertSame(3, Kernel::write($file, 'foo'));
        $this->assertSame('foo', Kernel::read($file));

        $this->assertSame(6, Kernel::write($file, 'barbaz', FILE_APPEND));
        $this->assertSame('foobarbaz', Kernel::read($file));

        unlink($file);
    }
}
