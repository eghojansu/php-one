<?php

namespace Tests\Feature;

class Obj
{
    private $data = array();

    public function __isset($offset)
    {
        return isset($this->data[$offset]);
    }

    public function &__get($offset)
    {
        $var = &$this->data[$offset];

        return $var;
    }

    public function __set($offset,  $value)
    {
        $this->data[$offset] = $value;
    }

    public function __unset($offset)
    {
        unset($this->data[$offset]);
    }
}
