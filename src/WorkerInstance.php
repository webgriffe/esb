<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\delay;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\ErroredJobEvent;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\ReservedJobEvent;
use Webgriffe\Esb\Model\WorkedJobEvent;
use Webgriffe\Esb\Service\ElasticSearch;
use function Amp\call;

final class WorkerInstance implements WorkerInstanceInterface
{
    /**
     * @var FlowConfig
     */
    private $flowConfig;
    /**
     * @var int
     */
    private $instanceId;
    /**
     * @var WorkerInterface
     */
    private $worker;
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var ElasticSearch
     */
    private $elasticSearch;

    /**
     * @var array
     */
    private static $workCounts = [];

    public function __construct(
        FlowConfig $flowConfig,
        int $instanceId,
        WorkerInterface $worker,
        BeanstalkClient $beanstalkClient,
        LoggerInterface $logger,
        ElasticSearch $elasticSearch
    ) {
        $this->flowConfig = $flowConfig;
        $this->instanceId = $instanceId;
        $this->worker = $worker;
        $this->beanstalkClient = $beanstalkClient;
        $this->logger = $logger;
        $this->elasticSearch = $elasticSearch;
    }

    public function boot(): Promise
    {
        return call(function () {
            yield $this->worker->init();
            yield $this->beanstalkClient->watch($this->flowConfig->getTube());
            yield $this->beanstalkClient->ignore('default');

            $workerFqcn = \get_class($this->worker);
            $this->logger->info(
                'A Worker instance has been successfully initialized',
                [
                    'flow' => $this->flowConfig->getDescription(),
                    'worker' => $workerFqcn,
                    'instance_id' => $this->instanceId
                ]
            );

            $lastProcessTimestamp = 0;

            while ($rawJob = yield $this->beanstalkClient->reserve()) {
                yield $this->waitForDependencies($lastProcessTimestamp);

                list($jobBeanstalkId, $jobUuid) = $rawJob;
                $logContext = [
                    'flow' => $this->flowConfig->getDescription(),
                    'worker' => $workerFqcn,
                    'instance_id' => $this->instanceId,
                    'job_beanstalk_id' => $jobBeanstalkId,
                    'job_uuid' => $jobUuid
                ];

                try {
                    /** @var Job $job */
                    $job = yield $this->elasticSearch->fetchJob($jobUuid, $this->flowConfig->getTube());
                } catch (\Throwable $exception) {
                    yield $this->beanstalkClient->bury($jobBeanstalkId);
                    $this->logger->critical(
                        'Cannot fetch job from ElasticSearch. Job has been buried.',
                        array_merge($logContext, ['exception_message' => $exception->getMessage()])
                    );
                    $lastProcessTimestamp = microtime(true);
                    continue;
                }
                $job->addEvent(new ReservedJobEvent(new \DateTime(), $workerFqcn));
                yield $this->elasticSearch->indexJob($job, $this->flowConfig->getTube());
                $payloadData = $job->getPayloadData();
                $logContext['payload_data'] = NonUtf8Cleaner::clean($payloadData);

                $this->logger->info('Worker reserved a Job', $logContext);

                try {
                    if (!array_key_exists($jobBeanstalkId, self::$workCounts)) {
                        self::$workCounts[$jobBeanstalkId] = 0;
                    }
                    ++self::$workCounts[$jobBeanstalkId];

                    yield $this->worker->work($job);
                    $job->addEvent(new WorkedJobEvent(new \DateTime(), $workerFqcn));
                    yield $this->elasticSearch->indexJob($job, $this->flowConfig->getTube());
                    $this->logger->info('Successfully worked a Job', $logContext);

                    yield $this->beanstalkClient->delete($jobBeanstalkId);
                    unset(self::$workCounts[$jobBeanstalkId]);
                } catch (\Throwable $e) {
                    $job->addEvent(new ErroredJobEvent(new \DateTime(), $workerFqcn, $e->getMessage()));
                    yield $this->elasticSearch->indexJob($job, $this->flowConfig->getTube());
                    $this->logger->notice(
                        'An error occurred while working a Job.',
                        array_merge(
                            $logContext,
                            ['work_count' => self::$workCounts[$jobBeanstalkId], 'error' => $e->getMessage()]
                        )
                    );

                    if (self::$workCounts[$jobBeanstalkId] >= $this->flowConfig->getWorkerMaxRetry()) {
                        yield $this->beanstalkClient->delete($jobBeanstalkId);
                        $this->logger->error(
                            'A Job reached maximum work retry limit and has been removed from queue.',
                            array_merge(
                                $logContext,
                                [
                                    'last_error' => $e->getMessage(),
                                    'max_retry' => $this->flowConfig->getWorkerMaxRetry()
                                ]
                            )
                        );
                        unset(self::$workCounts[$jobBeanstalkId]);
                        $lastProcessTimestamp = microtime(true);
                        continue;
                    }

                    yield $this->beanstalkClient->release($jobBeanstalkId, $this->flowConfig->getWorkerReleaseDelay());
                    $this->logger->info(
                        'Worker released a Job',
                        array_merge($logContext, ['release_delay' => $this->flowConfig->getWorkerReleaseDelay()])
                    );
                }

                $lastProcessTimestamp = microtime(true);
            }
        });
    }

    /**
     * @inheritDoc
     */
    public function getWorker(): WorkerInterface
    {
        return $this->worker;
    }

    /**
     * @param float $lastProcessTimestamp
     * @return Promise
     */
    private function waitForDependencies(float $lastProcessTimestamp): Promise
    {
        return call(function () use ($lastProcessTimestamp) {
            if (count($this->flowConfig->getDependsOn()) > 0) {
                if ((microtime(true) - $lastProcessTimestamp) > 1) {
                    //If more than one second has passed since the last job finished processing and if this flow depends
                    //on something, then do not start processing the new job right away but wait for a bit.
                    //Suppose that there are two flows, flow A and flow B, and that flow A depends on flow B. Also
                    //suppose that the two producers are given data to generate one job each at the same time. Due to
                    //unpredictable timing issues, flow A's producer may generate a job first and the worker may
                    //retrieve it before flow B's producer has had a chance to produce its job and add it to its tube.
                    //So flow A may start working its job before flow B has a chance to stop it.
                    //This delay is intended to give flow B's producer enough time to generate its job and to send it
                    //to the tube, so that it can then stop flow A from running for as long as needed by flow B.
                    //However, if flow A is processing a long sequence of jobs, then this delay is avoided for
                    //performance reasons
                    yield delay($this->flowConfig->getDelayAfterIdleTime());
                }

                //If this flow depends on something, then wait until all tubes that this depends on are empty.
                //Notice that this means that they must all be empty AT THE SAME TIME. This means that it must be
                //possible to query each dependency tube and ALL of them must be empty in a single pass of the loop.
                do {
                    $hadToWaitForSomeDependency = false;
                    foreach ($this->flowConfig->getDependsOn() as $dependency) {
                        $sleepTime = $this->flowConfig->getInitialPollingInterval();
                        while (true) {
                            $tubeStats = yield $this->beanstalkClient->getTubeStats($dependency);
                            if ($tubeStats->currentJobsReady + $tubeStats->currentJobsReserved === 0) {
                                break;
                            }
                            $hadToWaitForSomeDependency = true;

                            yield delay($sleepTime);

                            //Exponentially increase the wait time up to one minute after every failed check
                            $sleepTime = min(
                                (int)($sleepTime*$this->flowConfig->getPollingIntervalMultiplier()),
                                $this->flowConfig->getMaximumPollingInterval()
                            );
                        }
                    }
                } while ($hadToWaitForSomeDependency);
            }
        });
    }
}
