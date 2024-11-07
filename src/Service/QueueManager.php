<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Exception\ElasticSearch\JobNotFoundException;
use Webgriffe\Esb\Exception\FatalQueueException;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\JobInterface;
use Webgriffe\Esb\NonUtf8Cleaner;

final class QueueManager implements ProducerQueueManagerInterface, WorkerQueueManagerInterface
{
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;

    /**
     * @var ElasticSearch
     */
    private $elasticSearch;

    /**
     * @var FlowConfig
     */
    private $flowConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var JobInterface[]
     */
    private $batch = [];

    /**
     * @TODO This map is static because it must be shared between each QueueManager instance: it could be refactored
     *       extracting the mapping service to a dedicated class
     * @var int[]
     */
    private static $uuidToBeanstalkIdMap = [];
    /**
     * @var int
     */
    private $batchSize;

    public function __construct(
        FlowConfig $flowConfig,
        BeanstalkClient $beanstalkClient,
        ElasticSearch $elasticSearch,
        LoggerInterface $logger,
        int $batchSize
    ) {
        $this->flowConfig = $flowConfig;
        $this->beanstalkClient = $beanstalkClient;
        $this->elasticSearch = $elasticSearch;
        $this->logger = $logger;
        $this->batchSize = $batchSize;
    }

    /**
     * @inheritdoc
     */
    public function boot(): Promise
    {
        return call(function () {
            //Producer
            yield $this->beanstalkClient->use($this->flowConfig->getTube());

            //Worker
            yield $this->beanstalkClient->watch($this->flowConfig->getTube());
            yield $this->beanstalkClient->ignore('default');
        });
    }

    /**
     * @inheritdoc
     */
    public function enqueue(JobInterface $job): Promise
    {
        return call(function () use ($job) {
            $jobExists = yield $this->jobExists($job->getUuid());
            if ($jobExists) {
                throw new \RuntimeException(
                    sprintf(
                        'A job with UUID "%s" already exists but this should be a new job.',
                        $job->getUuid()
                    )
                );
            }
            $this->batch[$job->getUuid()] = $job;

            $count = count($this->batch);
            if ($count < $this->batchSize) {
                return 0;   //Number of jobs actually added to the queue
            }

            yield from $this->processBatch();
            return $count;
        });
    }

    /**
     * @inheritdoc
     */
    public function flush(): Promise
    {
        return call(function () {
            $jobsCount = count($this->batch);
            if ($jobsCount > 0) {
                yield from $this->processBatch();
            }
            return $jobsCount;
        });
    }

    /**
     * @inheritdoc
     */
    public function getNextJob(): Promise
    {
        return call(function () {
            try {
                $rawJob = yield $this->beanstalkClient->reserve();
            } catch (\Exception $ex) {
                throw new FatalQueueException($ex->getMessage(), $ex->getCode(), $ex);
            }

            list($jobBeanstalkId, $jobUuid) = $rawJob;

            try {
                /** @var Job $job */
                $job = yield $this->elasticSearch->fetchJob($jobUuid, $this->flowConfig->getTube());
            } catch (\Throwable $exception) {
                yield $this->beanstalkClient->bury($jobBeanstalkId);

                throw new JobNotFoundException($jobUuid, 0, $exception);
            }

            $this->saveJobBeanstalkId($job, $jobBeanstalkId);

            return $job;
        });
    }

    /**
     * @inheritdoc
     */
    public function updateJob(JobInterface $job): Promise
    {
        return call(function () use ($job) {
            yield $this->elasticSearch->indexJob($job, $this->flowConfig->getTube());
        });
    }

    /**
     * @inheritdoc
     */
    public function requeue(JobInterface $job, int $delay = 0): Promise
    {
        return call(function () use ($job, $delay) {
            //Leave the job in Elasticsearch. Only delete it from Beanstalk
            $jobBeanstalkId = $this->getJobBeanstalkId($job);
            yield $this->beanstalkClient->release($jobBeanstalkId, $delay);
        });
    }

    /**
     * @inheritdoc
     */
    public function dequeue(JobInterface $job): Promise
    {
        return call(function () use ($job) {
            //Leave the job in Elasticsearch. Only delete it from Beanstalk
            $jobBeanstalkId = $this->getJobBeanstalkId($job);
            yield $this->beanstalkClient->delete($jobBeanstalkId);
        });
    }

    /**
     * @inheritdoc
     */
    public function isEmpty(string $queueName): Promise
    {
        return call(function () use ($queueName) {
            $tubeStats = yield $this->beanstalkClient->getTubeStats($queueName);
            return ($tubeStats->currentJobsReady + $tubeStats->currentJobsReserved + $tubeStats->currentJobsDelayed) === 0;
        });
    }

    /**
     * @param string $jobUuid
     * @return Promise<bool>
     */
    private function jobExists(string $jobUuid): Promise
    {
        return call(function () use ($jobUuid) {
            try {
                yield $this->elasticSearch->fetchJob($jobUuid, $this->flowConfig->getTube());
                return true;
            } catch (JobNotFoundException $exception) {
                return false;
            }
        });
    }

    /**
     * @return \Generator<Promise>
     */
    private function processBatch(): \Generator
    {
        $this->logger->debug('Processing batch');
        $result = yield $this->elasticSearch->bulkIndexJobs($this->batch, $this->flowConfig->getTube());

        if ($result['errors'] === true) {
            foreach ($result['items'] as $item) {
                if (!array_key_exists('index', $item)) {
                    $this->logger->error(
                        'Unexpected response item in bulk index response',
                        ['bulk_index_response_item' => $item]
                    );
                    continue;
                }
                $itemStatusCode = $item['index']['status'] ?? null;
                if (!$this->isSuccessfulStatusCode($itemStatusCode)) {
                    $uuid = $item['index']['_id'];
                    unset($this->batch[$uuid]);
                    $this->logger->error(
                        'Job could not be indexed in ElasticSearch',
                        ['bulk_index_response_item' => $item]
                    );
                }
            }
        }

        foreach ($this->batch as $singleJob) {
            yield $this->beanstalkClient->put(
                $singleJob->getUuid(),
                $singleJob->getTimeout(),
                $singleJob->getDelay(),
                $singleJob->getPriority()
            );
            $this->logger->info(
                'Successfully enqueued a new Job',
                [
                    'flow_name' => $this->flowConfig->getName(),
                    'job_uuid' => $singleJob->getUuid(),
                    'payload_data' => NonUtf8Cleaner::clean($singleJob->getPayloadData())
                ]
            );
        }

        $this->batch = [];
    }

    /**
     * @param JobInterface $job
     * @param int $jobBeanstalkId
     */
    private function saveJobBeanstalkId(JobInterface $job, int $jobBeanstalkId): void
    {
        self::$uuidToBeanstalkIdMap[$job->getUuid()] = $jobBeanstalkId;
    }

    /**
     * @param JobInterface $job
     * @return int
     */
    private function getJobBeanstalkId(JobInterface $job): int
    {
        $uuid = $job->getUuid();
        if (array_key_exists($uuid, self::$uuidToBeanstalkIdMap)) {
            return self::$uuidToBeanstalkIdMap[$uuid];
        }

        throw new \RuntimeException("Unknown Beanstalk id for job {$uuid}");
    }

    public function isSuccessfulStatusCode(?int $statusCode): bool
    {
        return $statusCode !== null && $statusCode >= 200 && $statusCode < 300;
    }
}
