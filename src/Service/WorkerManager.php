<?php

namespace Webgriffe\Esb\Service;

use Amp\CallableMaker;
use Amp\Loop;
use Monolog\Logger;
use Webgriffe\Esb\Model\QueuedJob;
use Webgriffe\Esb\NonUtf8Cleaner;
use Webgriffe\Esb\WorkerInterface;
use function Amp\call;

class WorkerManager
{
    use CallableMaker;

    /**
     * @var BeanstalkClientFactory
     */
    private $beanstalkClientFactory;

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
     * @param BeanstalkClientFactory $beanstalkClientFactory
     * @param Logger $logger
     */
    public function __construct(BeanstalkClientFactory $beanstalkClientFactory, Logger $logger)
    {
        $this->beanstalkClientFactory = $beanstalkClientFactory;
        $this->logger = $logger;
    }

    public function bootWorkers()
    {
        if (!\count($this->workers)) {
            $this->logger->notice('No workers to start.');
            return;
        }

        foreach ($this->workers as $worker) {
            for ($instanceIndex = 1; $instanceIndex <= $worker->getInstancesCount(); $instanceIndex++) {
                Loop::defer(function () use ($worker, $instanceIndex) {
                    yield call($this->callableFromInstanceMethod('bootWorkerInstance'), $worker, $instanceIndex);
                });
            }
        }
    }

    /**
     * @param WorkerInterface $worker
     * @param int             $instanceIndex
     *
     * @return \Generator
     */
    private function bootWorkerInstance(WorkerInterface $worker, int $instanceIndex): \Generator
    {
        $beanstalkClient = $this->beanstalkClientFactory->create();

        yield $worker->init();
        yield $beanstalkClient->watch($worker->getTube());
        yield $beanstalkClient->ignore('default');

        $this->logger->info(
            'A Worker has been successfully initialized',
            ['worker' => \get_class($worker), 'instance_index' => $instanceIndex]
        );

        while ($rawJob = yield $beanstalkClient->reserve()) {
            $job = new QueuedJob($rawJob[0], unserialize($rawJob[1], ['allowed_classes' => false]));

            $logContext = [
                'worker' => \get_class($worker),
                'instance_index' => $instanceIndex,
                'job_id' => $job->getId(),
                'payload_data' => NonUtf8Cleaner::clean($job->getPayloadData())
            ];
            $this->logger->info('Worker reserved a Job', $logContext);

            try {
                if (!array_key_exists($job->getId(), $this->workCounts)) {
                    $this->workCounts[$job->getId()] = 0;
                }
                ++$this->workCounts[$job->getId()];

                yield $worker->work($job);
                $this->logger->info('Successfully worked a Job', $logContext);

                yield $beanstalkClient->delete($job->getId());
                unset($this->workCounts[$job->getId()]);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'An error occurred while working a Job.',
                    array_merge($logContext, ['error' => $e->getMessage()])
                );

                if ($this->workCounts[$job->getId()] >= 5) {
                    yield $beanstalkClient->bury($job->getId());
                    $this->logger->critical(
                        'A Job reached maximum work retry limit and has been buried',
                        array_merge($logContext, ['last_error' => $e->getMessage()])
                    );
                    unset($this->workCounts[$job->getId()]);
                    continue;
                }

                yield $beanstalkClient->release($job->getId(), $worker->getReleaseDelay());
                $this->logger->info('Worker released a Job', $logContext);
            }
        }
    }

    /**
     * @param WorkerInterface $worker
     */
    public function addWorker(WorkerInterface $worker)
    {
        $this->workers[] = $worker;
    }
}
