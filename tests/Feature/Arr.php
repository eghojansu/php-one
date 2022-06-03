<?php

namespace Tests\Feature;

class Arr implements \ArrayAccess
{
    private $data = array();

    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function &offsetGet($offset)
    {
        $var = &$this->data[$offset];

        return $var;
    }

    public function offsetSet($offset, $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
    }
}
