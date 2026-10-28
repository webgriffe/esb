<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

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
use Webgriffe\Esb\Service\QueueBackendInterface;

final class WorkerInstance implements WorkerInstanceInterface
{
    /** @var array<int> */
    private static array $workCounts = [];

    public function __construct(
        private readonly FlowConfig $flowConfig,
        private readonly int $instanceId,
        private readonly WorkerInterface $worker,
        private readonly LoggerInterface $logger,
        private readonly QueueBackendInterface $queueBackend
    ) {
    }

    public function boot(): Promise
    {
        return call(function () {
            yield $this->worker->init();
            yield $this->queueBackend->boot();

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
                    $job = yield $this->queueBackend->getNextJob();
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
                yield $this->updateJob($job, $logContext);
                $payloadData = $job->getPayloadData();
                $logContext['payload_data'] = NonUtf8Cleaner::clean($payloadData);

                $this->logger->info('Worker reserved a Job', $logContext);

                if (!array_key_exists($jobUuid, self::$workCounts)) {
                    self::$workCounts[$jobUuid] = 0;
                }
                ++self::$workCounts[$jobUuid];

                try {
                    yield $this->worker->work($job);
                } catch (\Throwable $e) {
                    yield $this->handleWorkError($job, $e, $logContext);

                    continue;
                }

                unset(self::$workCounts[$jobUuid]);
                $job->addEvent(new WorkedJobEvent(new \DateTime(), $workerFqcn));

                yield $this->updateJob($job, $logContext);

                $this->logger->info('Successfully worked a Job', $logContext);

                try {
                    yield $this->queueBackend->dequeue($job);
                } catch (\Throwable $e) {
                    $this->logger->critical(
                        'Could not remove a successfully worked Job from the queue, it may be worked again.',
                        array_merge($logContext, ['error' => $e->getMessage()])
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
     * @param array<string, mixed> $logContext
     *
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
                            if (yield $this->queueBackend->isEmpty($dependency)) {
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

    /**
     * @param array<string, mixed> $logContext
     *
     * @return Promise<void>
     */
    private function handleWorkError(
        JobInterface $job,
        \Throwable $error,
        array $logContext
    ): Promise {
        return call(function () use ($job, $error, $logContext) {
            $jobUuid = $job->getUuid();
            $workerFqcn = \get_class($this->worker);
            $job->addEvent(new ErroredJobEvent(new \DateTime(), $workerFqcn, $error->getMessage()));

            yield $this->updateJob($job, $logContext);

            $workCount = self::$workCounts[$jobUuid];

            $this->logger->notice(
                'An error occurred while working a Job.',
                array_merge(
                    $logContext,
                    [
                        'work_count' => $workCount,
                        'error' => $error->getMessage(),
                        'stack_trace' => $error->getTraceAsString(),
                    ]
                )
            );

            try {
                if ($workCount >= $this->flowConfig->getWorkerMaxRetry()) {
                    unset(self::$workCounts[$jobUuid]);

                    yield $this->queueBackend->dequeue($job);

                    $this->logger->error(
                        'A Job reached maximum work retry limit and has been removed from queue.',
                        array_merge(
                            $logContext,
                            [
                                'last_error' => $error->getMessage(),
                                'max_retry' => $this->flowConfig->getWorkerMaxRetry()
                            ]
                        )
                    );
                    return;
                }

                yield $this->queueBackend->requeue($job, $this->flowConfig->getWorkerErrorRetryDelay());
                $this->logger->info(
                    'Worker released a Job',
                    array_merge($logContext, ['error_retry_delay' => $this->flowConfig->getWorkerErrorRetryDelay()])
                );
            } catch (\Throwable $e) {
                $this->logger->critical(
                    'Could not dequeue or release a failed Job.',
                    array_merge($logContext, ['error' => $e->getMessage()])
                );
            }
        });
    }

    /**
     * Job updates only keep track of the job history, so a failure must not stop the worker loop
     *
     * @param array<string, mixed> $logContext
     *
     * @return Promise<void>
     */
    private function updateJob(JobInterface $job, array $logContext): Promise
    {
        return call(function () use ($job, $logContext) {
            try {
                yield $this->queueBackend->updateJob($job);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Could not update a Job in the queue backend.',
                    array_merge($logContext, ['error' => $e->getMessage()])
                );
            }
        });
    }
}
