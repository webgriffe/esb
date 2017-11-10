<?php

use Webgriffe\Esb\Kernel;
use Webgriffe\Esb\Service\WorkerManager;

require_once __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel();
$container = $kernel->getContainer();

/** @var \Webgriffe\Esb\Service\WorkerManager $workerManager */
$workerManager = $container->get(WorkerManager::class);
$workerManager->startAllWorkers();

exit(1);
