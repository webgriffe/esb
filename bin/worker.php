<?php

use Webgriffe\Esb\Kernel;
use Webgriffe\Esb\WorkerManager;

require_once __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel();
$container = $kernel->getContainer();

/** @var WorkerManager $workerManager */
$workerManager = $container->get('worker_manager');
$workerManager->startAllWorkers();

exit(1);
