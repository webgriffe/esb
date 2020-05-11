<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\delay;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\QueuedJob;
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
     * @var array
     */
    private static $workCounts = [];

    public function __construct(
        FlowConfig $flowConfig,
        int $instanceId,
        WorkerInterface $worker,
        BeanstalkClient $beanstalkClient,
        LoggerInterface $logger
    ) {
        $this->flowConfig = $flowConfig;
        $this->instanceId = $instanceId;
        $this->worker = $worker;
        $this->beanstalkClient = $beanstalkClient;
        $this->logger = $logger;
    }

    public function boot(): Promise
    {
        return call(function () {
            yield $this->worker->init();
            yield $this->beanstalkClient->watch($this->flowConfig->getTube());
            yield $this->beanstalkClient->ignore('default');

            $this->logger->info(
                'A Worker instance has been successfully initialized',
                [
                    'flow' => $this->flowConfig->getDescription(),
                    'worker' => \get_class($this->worker),
                    'instance_id' => $this->instanceId
                ]
            );

            $dependencies = $this->flowConfig->getDependsOn();
            $lastProcessTimestamp = 0;

            while ($rawJob = yield $this->beanstalkClient->reserve()) {
                if (count($dependencies) > 0 && ((microtime(true) - $lastProcessTimestamp) > 1)) {
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
                    yield delay(1000);
                }

                //If this flow depends on something, then wait until all tubes that this depends on are empty.
                //Notice that this means that they must all be empty AT THE SAME TIME. This means that it must be
                //possible to query each dependency tube and ALL of them must be empty in a single pass of the loop.
                do {
                    $hadToWaitForSomeDependency = false;
                    foreach ($dependencies as $dependency) {
                        $sleepTime = 1000;  //Milliseconds
                        while (true) {
                            $tubeStats = yield $this->beanstalkClient->getTubeStats($dependency);
                            if ($tubeStats->currentJobsReady + $tubeStats->currentJobsReserved == 0) {
                                break;
                            }
                            $hadToWaitForSomeDependency = true;

                            yield delay($sleepTime);

                            //Exponentially increase the wait time up to one minute after every failed check
                            $sleepTime = min($sleepTime*2, 60000);
                        }
                    }
                } while ($hadToWaitForSomeDependency);

                list($jobBeanstalkId, $rawPayload) = $rawJob;

                $logContext = [
                    'flow' => $this->flowConfig->getDescription(),
                    'worker' => \get_class($this->worker),
                    'instance_id' => $this->instanceId,
                    'job_id' => $jobBeanstalkId,
                ];

                $payloadData = @unserialize($rawPayload, ['allowed_classes' => false]);
                if ($payloadData === false) {
                    $logContext['raw_payload'] = NonUtf8Cleaner::cleanString($rawPayload);
                    yield $this->beanstalkClient->bury($jobBeanstalkId);
                    $this->logger->critical('Cannot unserialize job payload so it has been buried.', $logContext);

                    $lastProcessTimestamp = microtime(true);
                    continue;
                }
                $job = new QueuedJob($jobBeanstalkId, $payloadData);
                $logContext['payload_data'] = NonUtf8Cleaner::clean($job->getPayloadData());

                $this->logger->info('Worker reserved a Job', $logContext);

                try {
                    if (!array_key_exists($job->getId(), self::$workCounts)) {
                        self::$workCounts[$job->getId()] = 0;
                    }
                    ++self::$workCounts[$job->getId()];

                    yield $this->worker->work($job);
                    $this->logger->info('Successfully worked a Job', $logContext);

                    yield $this->beanstalkClient->delete($job->getId());
                    unset(self::$workCounts[$job->getId()]);
                } catch (\Throwable $e) {
                    $this->logger->notice(
                        'An error occurred while working a Job.',
                        array_merge(
                            $logContext,
                            ['work_count' => self::$workCounts[$job->getId()], 'error' => $e->getMessage()]
                        )
                    );

                    if (self::$workCounts[$job->getId()] >= $this->flowConfig->getWorkerMaxRetry()) {
                        yield $this->beanstalkClient->bury($job->getId());
                        $this->logger->error(
                            'A Job reached maximum work retry limit and has been buried',
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

                    yield $this->beanstalkClient->release($job->getId(), $this->flowConfig->getWorkerReleaseDelay());
                    $this->logger->info(
                        'Worker released a Job',
                        array_merge($logContext, ['release_delay' => $this->flowConfig->getWorkerReleaseDelay()])
                    );
                }

                $lastProcessTimestamp = microtime(true);
            }
        });
    }
}
