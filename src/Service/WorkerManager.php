<?php

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Loop;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\WorkerInterface;

class WorkerManager
{
    /**
     * @var BeanstalkClient
     */
    private $beanstalk;

    /**
     * @var \Webgriffe\Esb\WorkerInterface[]
     */
    private $workers = [];

    /**
     * WorkerManager constructor.
     * @param BeanstalkClient $beanstalk
     */
    public function __construct(BeanstalkClient $beanstalk)
    {
        $this->beanstalk = $beanstalk;
    }

    public function bootWorkers()
    {
        if (!count($this->workers)) {
            printf('No workers to start.' . PHP_EOL);
            return;
        }

        printf('Starting "%s" workers...' . PHP_EOL, count($this->workers));
        foreach ($this->workers as $worker) {
            Loop::defer(function () use ($worker){
                yield $this->beanstalk->watch($worker->getTube());
                yield $this->beanstalk->ignore('default');
                while ($rawJob = yield $this->beanstalk->reserve()) {
                    $job = new Job($rawJob[0], $rawJob[1]);
                    try {
                        $worker->work($job);
                        $this->beanstalk->delete($job->getId());
                    } catch (\Exception $e) {
                        // TODO worker failure error handling
                    }
                }
            });
        }
    }

    public function addWorker(WorkerInterface $worker)
    {
        $this->workers[] = $worker;
    }
}
