<?php

require_once __DIR__ . '/../vendor/autoload.php';

$kernel = new \Webgriffe\Esb\Kernel();

$pheanstalk = $kernel->getContainer()->get(\Pheanstalk\PheanstalkInterface::class);

$pheanstalk
    ->useTube('testtube')
    ->put("job payload goes here\n");

