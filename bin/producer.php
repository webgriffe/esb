<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Pheanstalk\Pheanstalk;

$pheanstalk = new Pheanstalk('127.0.0.1', 32768);

$pheanstalk
    ->useTube('testtube')
    ->put("job payload goes here\n");

