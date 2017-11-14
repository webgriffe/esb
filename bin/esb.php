<?php

use Webgriffe\Esb\Kernel;

require_once __DIR__ . '/../vendor/autoload.php';

$config = $argv[1];
if (!$config) {
    echo 'Please provide the configuration file path.';
    exit(1);
}

$kernel = new Kernel($config);
$kernel->boot();
