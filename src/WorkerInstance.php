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

            while ($rawJob = yield $this->beanstalkClient->reserve()) {
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
                            $sleepTime = min($sleepTime*2, 10000);
                        }
                    }
                } while ($hadToWaitForSomeDependency);

                list($jobId, $rawPayload) = $rawJob;
                $logContext = [
                    'flow' => $this->flowConfig->getDescription(),
                    'worker' => \get_class($this->worker),
                    'instance_id' => $this->instanceId,
                    'job_id' => $jobId,
                ];

                $payloadData = @unserialize($rawPayload, ['allowed_classes' => false]);
                if ($payloadData === false) {
                    $logContext['raw_payload'] = NonUtf8Cleaner::cleanString($rawPayload);
                    yield $this->beanstalkClient->bury($jobId);
                    $this->logger->critical('Cannot unserialize job payload so it has been buried.', $logContext);
                    continue;
                }
                $job = new QueuedJob($jobId, $payloadData);
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
                        unset(self::$workCounts[$job->getId()]);
                        continue;
                    }

                    yield $this->beanstalkClient->release($job->getId(), $this->flowConfig->getWorkerReleaseDelay());
                    $this->logger->info(
                        'Worker released a Job',
                        array_merge($logContext, ['release_delay' => $this->flowConfig->getWorkerReleaseDelay()])
                    );
                }
            }
        });
    }
}
