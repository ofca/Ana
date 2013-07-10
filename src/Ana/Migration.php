<?php

namespace Ana;

abstract class Migration
{
    protected $connection;

    public function __construct($connection)
    {
        $this->connection = $connection;
    }
}