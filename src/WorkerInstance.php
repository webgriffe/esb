<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
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

            while ($rawJob = yield $this->beanstalkClient->reserve()) {
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
                        continue;
                    }

                    yield $this->beanstalkClient->release($jobBeanstalkId, $this->flowConfig->getWorkerReleaseDelay());
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
}
