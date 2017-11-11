<?php

namespace Webgriffe\Esb\Service;

use Amp\Loop;
use Webgriffe\Esb\Service\Worker\WorkerInterface;

class WorkerManager
{
    /**
     * @var WorkerInterface[]
     */
    private $workers = [];

    public function bootWorkers()
    {
        if (!count($this->workers)) {
            printf('No workers to start.' . PHP_EOL);
            return;
        }

        printf('Starting "%s" workers...' . PHP_EOL, count($this->workers));
        foreach ($this->workers as $worker) {
            Loop::defer([$worker, 'work']);
        }
    }

    public function addWorker(WorkerInterface $worker)
    {
        $this->workers[] = $worker;
    }
}
