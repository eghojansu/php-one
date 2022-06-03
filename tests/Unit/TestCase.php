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

    protected function setUp(): void
    {
        if ($this->kernelize) {
            $this->kernel = new Kernel();
        }
    }

    protected function tearDown(): void
    {
        if ($this->kernelize) {
            // TODO: clearing
        }
    }
}
