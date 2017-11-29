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
    const RELEASE_DELAY = 30;
    const RELEASE_PRIORITY = 0;
    const BURY_PRIORITY = 0;

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
     * @var array
     */
    private $workCounts = [];

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
                    $jobId = $rawJob[0];
                    $job = new QueuedJob($jobId, unserialize($rawJob[1]));
                    try {
                        if (!array_key_exists($jobId, $this->workCounts)) {
                            $this->workCounts[$jobId] = 0;
                        }
                        ++$this->workCounts[$jobId];
                        yield call([$worker, 'work'], $job);
                        $this->logger->info(
                            'Successfully worked a QueuedJob',
                            ['worker' => get_class($worker), 'payload_data' => $job->getPayloadData()]
                        );
                        yield $this->beanstalk->delete($job->getId());
                        unset($this->workCounts[$jobId]);
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
                        if ($this->workCounts[$jobId] >= 5) {
                            yield $this->beanstalk->bury($jobId, self::BURY_PRIORITY);
                            $this
                                ->logger
                                ->critical(
                                    'Job reached maximum work retry limit, it will be buried.',
                                    [
                                        'worker' => get_class($worker),
                                        'job_id' => $jobId,
                                        'payload_data' => $job->getPayloadData(),
                                    ]
                                );
                            continue;
                        }
                        yield $this->beanstalk->release($jobId, self::RELEASE_DELAY, self::RELEASE_PRIORITY);
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
