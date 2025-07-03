<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use function Amp\delay;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Exception\FatalQueueException;
use Webgriffe\Esb\Model\ErroredJobEvent;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\JobInterface;
use Webgriffe\Esb\Model\ReservedJobEvent;
use Webgriffe\Esb\Model\WorkedJobEvent;
use Webgriffe\Esb\Service\ElasticSearch;
use Webgriffe\Esb\Service\QueueManager;
use Webgriffe\Esb\Service\WorkerQueueManagerInterface;

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
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var WorkerQueueManagerInterface
     */
    private $queueManager;

    /**
     * @var array<int>
     */
    private static $workCounts = [];

    public function __construct(
        FlowConfig $flowConfig,
        int $instanceId,
        WorkerInterface $worker,
        ?BeanstalkClient $beanstalkClient,
        LoggerInterface $logger,
        ?ElasticSearch $elasticSearch,
        ?WorkerQueueManagerInterface $queueManager = null
    ) {
        if ($beanstalkClient !== null) {
            trigger_deprecation(
                'webgriffe/esb',
                '2.2',
                'Passing a "%s" to "%s" is deprecated and will be removed in 3.0. ' .
                'Please pass a "%s" instead.',
                BeanstalkClient::class,
                __CLASS__,
                WorkerQueueManagerInterface::class
            );
        }
        if ($elasticSearch !== null) {
            trigger_deprecation(
                'webgriffe/esb',
                '2.2',
                'Passing a "%s" to "%s" is deprecated and will be removed in 3.0. ' .
                'Please pass a "%s" instead.',
                ElasticSearch::class,
                __CLASS__,
                WorkerQueueManagerInterface::class
            );
        }
        $this->flowConfig = $flowConfig;
        $this->instanceId = $instanceId;
        $this->worker = $worker;
        $this->logger = $logger;

        if ($queueManager === null) {
            trigger_deprecation(
                'webgriffe/esb',
                '2.2',
                'Not passing a "%s" to "%s" is deprecated and will be required in 3.0.',
                WorkerQueueManagerInterface::class,
                __CLASS__
            );

            if (!$beanstalkClient) {
                throw new \RuntimeException('Cannot create a QueueManager without the Beanstalk client!');
            }

            if (!$elasticSearch) {
                throw new \RuntimeException('Cannot create a QueueManager without the ElasticSearch client');
            }

            $queueManager = new QueueManager(
                $this->flowConfig,
                $beanstalkClient,
                $elasticSearch,
                $this->logger,
                1000
            );
        }
        $this->queueManager = $queueManager;
    }

    public function boot(): Promise
    {
        return call(function () {
            yield $this->worker->init();
            yield $this->queueManager->boot();

            $workerFqcn = \get_class($this->worker);
            $globalLogContext = [
                'flow' => $this->flowConfig->getDescription(),
                'worker' => $workerFqcn,
                'instance_id' => $this->instanceId,
            ];
            $this->logger->info('A Worker instance has been successfully initialized', $globalLogContext);

            $firstIteration = true;
            while (true) {
                //After processing any job, save the timestamp that the last job finished processing at.
                //The first time set this to 0 to ensure that the processing starts by assuming that the last job was
                //processed some time ago. Otherwise there may be race conditions among dependencies when the ESB first
                //starts up. See the "delay_after_idle_time" worker parameter
                if ($firstIteration) {
                    $lastProcessTimestamp = 0;
                } else {
                    $lastProcessTimestamp = microtime(true);
                }

                $firstIteration = false;

                try {
                    /** @var JobInterface $job */
                    if (!($job = yield $this->queueManager->getNextJob())) {
                        break;
                    }
                } catch (FatalQueueException $ex) {
                    //Let this pass to stop the loop
                    throw $ex;
                } catch (\Exception $ex) {
                    $this->logger->critical($ex->getMessage(), $globalLogContext);
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
                    yield $this->queueManager->updateJob($job);
                    $this->logger->notice(
                        'An error occurred while working a Job.',
                        array_merge(
                            $logContext,
                            ['work_count' => self::$workCounts[$jobUuid], 'error' => $e->getMessage()]
                        )
                    );

                    if (self::$workCounts[$jobUuid] >= $this->flowConfig->getWorkerMaxRetry()) {
                        yield $this->queueManager->dequeue($job);
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
                        continue;
                    }

                    yield $this->queueManager->requeue($job, $this->flowConfig->getWorkerReleaseDelay());
                    $this->logger->info(
                        'Worker released a Job',
                        array_merge($logContext, ['release_delay' => $this->flowConfig->getWorkerReleaseDelay()])
                    );
                }
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
     * @param array<string, mixed> $logContext
     * @return Promise<void>
     */
    private function waitForDependencies(float $lastProcessTimestamp, array $logContext): Promise
    {
        return call(function () use ($lastProcessTimestamp, $logContext) {
            if (count($this->flowConfig->getDependsOn()) > 0) {
                if (microtime(true) - $lastProcessTimestamp > 1) {
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
                            if (yield $this->queueManager->isEmpty($dependency)) {
                                break;
                            }
                            $this->logger->debug(
                                sprintf(
                                    'Flow %s has to wait for dependency %s to complete before it can work its jobs.',
                                    $this->flowConfig->getName(),
                                    $dependency
                                ),
                                $logContext
                            );
                            $hadToWaitForSomeDependency = true;
                            $allDependenciesWereIdle = false;

                            yield delay($sleepTime);

                            //Exponentially increase the wait time up to one minute after every failed check
                            $sleepTime = min(
                                (int)($sleepTime * $this->flowConfig->getPollingIntervalMultiplier()),
                                $this->flowConfig->getMaximumPollingInterval()
                            );
                        }
                    }
                } while ($hadToWaitForSomeDependency);

                if (!$allDependenciesWereIdle) {
                    $this->logger->debug(
                        sprintf(
                            'All dependencies of flow %s are idle. Proceeding to work the queued jobs.',
                            $this->flowConfig->getName()
                        ),
                        $logContext
                    );
                }
            }
        });
    }
}
