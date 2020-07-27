<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\delay;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Exception\ElasticSearch\JobNotFoundException;
use Webgriffe\Esb\Model\ErroredJobEvent;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\JobInterface;
use Webgriffe\Esb\Model\ReservedJobEvent;
use Webgriffe\Esb\Model\WorkedJobEvent;
use Webgriffe\Esb\Service\ElasticSearch;
use function Amp\call;
use Webgriffe\Esb\Service\QueueManager;

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
     * @var QueueManager
     */
    private $queueManager;

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
        ElasticSearch $elasticSearch,
        QueueManager $queueManager
    ) {
        $this->flowConfig = $flowConfig;
        $this->instanceId = $instanceId;
        $this->worker = $worker;
        $this->beanstalkClient = $beanstalkClient;
        $this->logger = $logger;
        $this->elasticSearch = $elasticSearch;
        $this->queueManager = $queueManager;
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

            $globalLogContext = [
                'flow' => $this->queueManager->getFlowDescription(),
                'worker' => $workerFqcn,
                'instance_id' => $this->instanceId,
            ];

            while (true) {
                try {
                    /** @var JobInterface $job */
                    $job = yield from $this->queueManager->getNextJob();
                    if (!$job) {
                        break;
                    }
                } catch (\Exception $ex) {
                    $this->logger->critical($ex->getMessage(), $logContext);

                    //@todo: is it correct to do this for every exception?
                    $lastProcessTimestamp = microtime(true);
                    continue;
                }

                $jobUuid = $job->getUuid();
                $logContext = $globalLogContext;
                $logContext['job_uuid'] = $jobUuid;

                yield $this->waitForDependencies($lastProcessTimestamp, $logContext);

                $job->addEvent(new ReservedJobEvent(new \DateTime(), $workerFqcn));
                yield $this->queueManager->updateJob($job);
                $payloadData = $job->getPayloadData();
                $logContext['payload_data'] = NonUtf8Cleaner::clean($payloadData);

                $this->logger->info('Worker reserved a Job', $logContext);

                try {
                    if (!array_key_exists($jobUuid, self::$workCounts)) {
                        self::$workCounts[$jobUuid] = 0;
                    }
                    ++self::$workCounts[$jobUuid];

                    yield $this->worker->work($job);

                    $job->addEvent(new WorkedJobEvent(new \DateTime(), $workerFqcn));
                    yield $this->queueManager->updateJob($job);
                    $this->logger->info('Successfully worked a Job', $logContext);

                    yield $this->queueManager->dequeue($job);
                    unset(self::$workCounts[$jobUuid]);
                } catch (\Throwable $e) {
                    $job->addEvent(new ErroredJobEvent(new \DateTime(), $workerFqcn, $e->getMessage()));
                    yield $this->elasticSearch->indexJob($job, $this->flowConfig->getTube());
                    $this->logger->notice(
                        'An error occurred while working a Job.',
                        array_merge(
                            $logContext,
                            ['work_count' => self::$workCounts[$jobUuid], 'error' => $e->getMessage()]
                        )
                    );

                    if (self::$workCounts[$jobUuid] >= $this->flowConfig->getWorkerMaxRetry()) {
                        yield $this->beanstalkClient->delete($jobUuid);
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
                        unset(self::$workCounts[$jobUuid]);
                        $lastProcessTimestamp = microtime(true);
                        continue;
                    }

                    yield $this->beanstalkClient->release($jobUuid, $this->flowConfig->getWorkerReleaseDelay());
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
    private function waitForDependencies(float $lastProcessTimestamp, array $logContext): Promise
    {
        return call(function () use ($lastProcessTimestamp, $logContext) {
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
                $allDependenciesWereIdle = true;
                do {
                    $hadToWaitForSomeDependency = false;
                    foreach ($this->flowConfig->getDependsOn() as $dependency) {
                        $sleepTime = $this->flowConfig->getInitialPollingInterval();
                        while (true) {
                            $tubeStats = yield $this->beanstalkClient->getTubeStats($dependency);
                            if ($tubeStats->currentJobsReady + $tubeStats->currentJobsReserved === 0) {
                                break;
                            }
                            $this->logger->debug(
                                sprintf(
                                    'Flow %s has to wait for dependency %s to complete before it can work its jobs.',
                                    $this->flowConfig->getTube(),
                                    $dependency
                                ),
                                $logContext
                            );
                            $hadToWaitForSomeDependency = true;
                            $allDependenciesWereIdle = false;

                            yield delay($sleepTime);

                            //Exponentially increase the wait time up to one minute after every failed check
                            $sleepTime = min(
                                (int)($sleepTime*$this->flowConfig->getPollingIntervalMultiplier()),
                                $this->flowConfig->getMaximumPollingInterval()
                            );
                        }
                    }
                } while ($hadToWaitForSomeDependency);

                if (!$allDependenciesWereIdle) {
                    $this->logger->debug(
                        sprintf(
                            'All dependencies of flow %s are idle. Proceeding to work the queued jobs.',
                            $this->flowConfig->getTube()
                        ),
                        $logContext
                    );
                }
            }
        });
    }
}
