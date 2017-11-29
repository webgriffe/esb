<?php

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\Loop;
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
                $this->logger->info('A Worker has been successfully initialized', ['worker' => get_class($worker)]);
                yield $this->beanstalk->watch($worker->getTube());
                yield $this->beanstalk->ignore('default');
                while ($rawJob = yield $this->beanstalk->reserve()) {
                    $job = new QueuedJob($rawJob[0], unserialize($rawJob[1]));
                    $logContext = [
                        'worker' => get_class($worker),
                        'job_id' => $job->getId(),
                        'payload_data' => $job->getPayloadData()
                    ];
                    $this->logger->info('Worker reserved a Job', $logContext);
                    try {
                        if (!array_key_exists($job->getId(), $this->workCounts)) {
                            $this->workCounts[$job->getId()] = 0;
                        }
                        ++$this->workCounts[$job->getId()];
                        yield call([$worker, 'work'], $job);
                        $this->logger->info('Successfully worked a Job', $logContext);
                        yield $this->beanstalk->delete($job->getId());
                        unset($this->workCounts[$job->getId()]);
                    } catch (\Exception $e) {
                        $this->logger->error(
                            'An error occurred while working a Job.',
                            array_merge($logContext, ['error' => $e->getMessage()])
                        );
                        if ($this->workCounts[$job->getId()] >= 5) {
                            yield $this->beanstalk->bury($job->getId(), self::BURY_PRIORITY);
                            $this->logger->critical(
                                'A Job reached maximum work retry limit and has been buried',
                                $logContext
                            );
                            unset($this->workCounts[$job->getId()]);
                            continue;
                        }
                        yield $this->beanstalk->release($job->getId(), self::RELEASE_DELAY, self::RELEASE_PRIORITY);
                        $this->logger->info('Worker released a Job', $logContext);
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
