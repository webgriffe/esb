<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\QueuedJob;
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
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var array
     */
    private static $workCounts = [];
    /**
     * @var ElasticSearch
     */
    private $elasticSearch;

    public function __construct(
        FlowConfig $flowConfig,
        int $instanceId,
        WorkerInterface $worker,
        BeanstalkClient $beanstalkClient,
        LoggerInterface $logger,
        SerializerInterface $serializer,
        ElasticSearch $elasticSearch
    ) {
        $this->flowConfig = $flowConfig;
        $this->instanceId = $instanceId;
        $this->worker = $worker;
        $this->beanstalkClient = $beanstalkClient;
        $this->logger = $logger;
        $this->serializer = $serializer;
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
                list($jobBeanstalkId, $rawPayload) = $rawJob;
                $logContext = [
                    'flow' => $this->flowConfig->getDescription(),
                    'worker' => $workerFqcn,
                    'instance_id' => $this->instanceId,
                    'job_id' => $jobBeanstalkId,
                ];

                try {
                    /** @var Job $job */
                    $job = $this->serializer->deserialize($rawPayload, Job::class, 'json');
                } catch (ExceptionInterface $exception) {
                    $logContext['raw_payload'] = NonUtf8Cleaner::cleanString($rawPayload);
                    yield $this->beanstalkClient->bury($jobBeanstalkId);
                    $this->logger->critical('Cannot deserialize job so it has been buried.', $logContext);
                    continue;
                }
                $job->addEvent(new ReservedJobEvent(new \DateTime(), $workerFqcn));
                yield $this->elasticSearch->indexJob($job);
                $payloadData = $job->getPayloadData();
                $logContext['payload_data'] = NonUtf8Cleaner::clean($payloadData);

                $this->logger->info('Worker reserved a Job', $logContext);

                try {
                    if (!array_key_exists($jobBeanstalkId, self::$workCounts)) {
                        self::$workCounts[$jobBeanstalkId] = 0;
                    }
                    ++self::$workCounts[$jobBeanstalkId];

                    // TODO Remove QueuedJob
                    yield $this->worker->work(new QueuedJob($jobBeanstalkId, $payloadData));
                    $job->addEvent(new WorkedJobEvent(new \DateTime(), $workerFqcn));
                    yield $this->elasticSearch->indexJob($job);
                    $this->logger->info('Successfully worked a Job', $logContext);

                    yield $this->beanstalkClient->delete($jobBeanstalkId);
                    unset(self::$workCounts[$jobBeanstalkId]);
                } catch (\Throwable $e) {
                    $this->logger->notice(
                        'An error occurred while working a Job.',
                        array_merge(
                            $logContext,
                            ['work_count' => self::$workCounts[$jobBeanstalkId], 'error' => $e->getMessage()]
                        )
                    );

                    if (self::$workCounts[$jobBeanstalkId] >= $this->flowConfig->getWorkerMaxRetry()) {
                        yield $this->beanstalkClient->bury($jobBeanstalkId);
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
}
