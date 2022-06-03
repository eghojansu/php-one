<?php

namespace Tests\Feature;

class Bag
{
    private $data = array();

    public function has($offset)
    {
        return isset($this->data[$offset]);
    }

    public function get($offset)
    {
        $var = &$this->data[$offset];

        return $var;
    }

    public function set($offset,  $value)
    {
        $this->data[$offset] = $value;
    }

    public function remove($offset)
    {
        unset($this->data[$offset]);
    }
}
