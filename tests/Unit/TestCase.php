<?php

namespace Tests\Unit;

use Ekok\One\Kernel;
use PHPUnit\Framework\TestCase as TestCaseBase;

abstract class TestCase extends TestCaseBase
{
    /** @var Kernel */
    protected $kernel;

    /** @var bool */
    protected $kernelize = false;

    protected function data(string $path = null): string
    {
        return dirname(__DIR__) . '/_data' . ($path ? '/' : null) . $path;
    }

    protected function tmp(string $path = null): string
    {
        return dirname(__DIR__, 2) . '/var' . ($path ? '/' : null) . $path;
    }

    protected function setUp(): void
    {
        if ($this->kernelize) {
            $this->kernel = new Kernel();
        }
    }

    protected function tearDown(): void
    {
        if ($this->kernelize) {
            $cache = $this->kernel['CACHE_DRIVER'];
            $ref = $this->kernel['CACHE_REF'];

            if ('folder' === $cache && $ref) {
                $files = glob($ref . '/*');

                array_walk($files, static fn ($file) => unlink($file));
            }
        }
    }
}
