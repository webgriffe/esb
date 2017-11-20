<?php

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Loop;
use Monolog\Logger;
use Webgriffe\Esb\Model\QueuedJob;
use Webgriffe\Esb\WorkerInterface;

class WorkerManager
{
    /**
     * @var BeanstalkClient
     */
    private $beanstalk;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var \Webgriffe\Esb\WorkerInterface[]
     */
    private $workers = [];

    /**
     * WorkerManager constructor.
     * @param BeanstalkClient $beanstalk
     * @param Logger $logger
     */
    public function __construct(BeanstalkClient $beanstalk, Logger $logger)
    {
        $this->beanstalk = $beanstalk;
        $this->logger = $logger;
    }

    public function bootWorkers()
    {
        if (!count($this->workers)) {
            $this->logger->notice('No workers to start.');
            return;
        }

        $this->logger->info(sprintf('Starting "%s" workers...', count($this->workers)));
        foreach ($this->workers as $worker) {
            Loop::defer(function () use ($worker){
                yield $this->beanstalk->watch($worker->getTube());
                yield $this->beanstalk->ignore('default');
                while ($rawJob = yield $this->beanstalk->reserve()) {
                    $job = new QueuedJob($rawJob[0], unserialize($rawJob[1]));
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
