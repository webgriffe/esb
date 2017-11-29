<?php

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\Loop;
use function Amp\Promise\wait;
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

        foreach ($this->workers as $worker) {
            Loop::defer(function () use ($worker){
                yield call([$worker, 'init']);
                $this->logger->info(sprintf('Worker "%s" successfully initialized.', get_class($worker)));
                yield $this->beanstalk->watch($worker->getTube());
                yield $this->beanstalk->ignore('default');
                while ($rawJob = yield $this->beanstalk->reserve()) {
                    $job = new QueuedJob($rawJob[0], unserialize($rawJob[1]));
                    try {
                        yield call([$worker, 'work'], $job);
                        $this->logger->info(
                            'Successfully worked a QueuedJob',
                            ['worker' => get_class($worker), 'payload_data' => $job->getPayloadData()]
                        );
                        yield $this->beanstalk->delete($job->getId());
                    } catch (\Exception $e) {
                        $this
                            ->logger
                            ->error(
                                'An error occurred while working a job.',
                                [
                                    'worker' => get_class($worker),
                                    'payload_data' => $job->getPayloadData(),
                                    'error' => $e->getMessage(),
                                ]
                            );
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
