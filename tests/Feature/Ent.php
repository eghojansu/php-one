<?php

namespace Tests\Feature;

class Ent
{
    private $_foo;

    public function hasFoo()
    {
        return isset($this->_foo);
    }

    public function &getFoo()
    {
        return $this->_foo;
    }

    public function setFoo($value)
    {
        $this->_foo = $value;

        return $this;
    }

    public function removeFoo()
    {
        $this->_foo = null;
    }
}
