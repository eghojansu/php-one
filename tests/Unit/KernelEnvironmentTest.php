<?php

namespace Tests\Unit;

class KernelEnvironmentTest extends TestCase
{
    protected $kernelize = true;

    public function testEnv()
    {
        $this->assertTrue($this->kernel->isProduction());
        $this->assertFalse($this->kernel->isDev());
        $this->assertFalse($this->kernel->isTest());
        $this->assertFalse($this->kernel->isDebug());
        $this->assertFalse($this->kernel->env('foo'));
        $this->assertTrue($this->kernel->env('prod'));
        $this->assertSame('prod', $this->kernel->env());
    }

    public function testEnvLoad()
    {
        $this->kernel->set('CONFIG_PATH', $this->data());
        $this->kernel->config(
            $this->data('config.ini'),
            $this->data('unknown_config.ini'),
        );

        $this->assertTrue($this->kernel['MAIN_CONFIG.PRODUCTION']);
        $this->assertTrue($this->kernel['MAIN_CONFIG.ENV_prod']);
        $this->assertSame(array(
            'env' => 'prod',
            'env_name' => 'prod',
            'path_delimiter' => DIRECTORY_SEPARATOR,
            'enabled' => true,
        ), $this->kernel['arr']);
        $this->assertSame(array(
            'foo' => 'BAR',
            'bar' => 'BAZ',
        ), $this->kernel['uppercased']);
        $this->assertSame(array(
            'foo' => 'bar',
            'one' => 1,
            'quoted' => 'text',
            'cite' => "cite'd",
            'two' => array(
                'lines' => "Two\nline",
            ),
            'three' => array(
                'lines' => "Line one\nLine two\nLine three",
            ),
        ), $this->kernel['embedded']);
    }
}
