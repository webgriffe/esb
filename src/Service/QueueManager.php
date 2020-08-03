<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Beanstalk\BeanstalkException;
use Amp\Beanstalk\ConnectionClosedException;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Exception\ElasticSearch\JobNotFoundException;
use Webgriffe\Esb\Exception\FatalQueueException;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\JobInterface;
use function Amp\call;

class QueueManager
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
     * static because it must be shared among all queue manager instances
     * @var string[]
     */
    private static $uuidToBeanstalkIdMap = [];

    public function __construct(
        FlowConfig $flowConfig,
        BeanstalkClient $beanstalkClient,
        ElasticSearch $elasticSearch,
        LoggerInterface $logger
    ) {
        $this->flowConfig = $flowConfig;
        $this->beanstalkClient = $beanstalkClient;
        $this->elasticSearch = $elasticSearch;
        $this->logger = $logger;
    }

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
     * @param JobInterface $job
     * @return int
     */
    public function enqueue(JobInterface $job)
    {
        $jobExists = yield $this->jobExists($job->getUuid());
        if ($jobExists) {
            throw new \RuntimeException(
                sprintf(
                    'A job with UUID "%s" already exists but this should be a new job.',
                    $job->getUuid()
                )
            );
        }
        $this->batch[] = $job;

        $count = count($this->batch);
        if ($count < 1000) {    //TODO: batch size should be a parameter
            return 0;   //Number of jobs actually added to the queue
        }

        yield from $this->processBatch();
        return $count;
    }

    /**
     * @return int
     */
    public function flush()
    {
        $jobsCount = count($this->batch);
        if ($jobsCount > 0) {
            yield from $this->processBatch();
        }
        return $jobsCount;
    }

    /**
     * @return JobInterface
     */
    public function getNextJob()
    {
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

            throw new JobNotFoundException(
                sprintf('Cannot fetch job %s from ElasticSearch. Job has been buried.', $jobUuid),
                0,
                $exception
            );
        }

        $this->saveJobBeanstalkId($job, $jobBeanstalkId);

        return $job;
    }

    public function updateJob(JobInterface $job)
    {
        yield $this->elasticSearch->indexJob($job, $this->flowConfig->getTube());
    }

    public function requeue(JobInterface $job, int $delay = 0)
    {
        //Leave the job in Elasticsearch. Only delete it from Beanstalk
        $jobBeanstalkId = $this->getJobBeanstalkId($job);
        yield $this->beanstalkClient->release($jobBeanstalkId, $delay);
    }

    public function dequeue(JobInterface $job)
    {
        //Leave the job in Elasticsearch. Only delete it from Beanstalk
        $jobBeanstalkId = $this->getJobBeanstalkId($job);
        yield $this->beanstalkClient->delete($jobBeanstalkId);
    }

    /**
     * @param string $queueName
     * @return bool
     */
    public function isEmpty(string $queueName)
    {
        $tubeStats = yield $this->beanstalkClient->getTubeStats($queueName);
        return ($tubeStats->currentJobsReady + $tubeStats->currentJobsReserved + $tubeStats->currentJobsDelayed) === 0;
    }

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
     * @return \Generator
     */
    private function processBatch(): \Generator
    {
        $this->logger->debug('Processing batch');
        yield $this->elasticSearch->bulkIndexJobs($this->batch, $this->flowConfig->getTube());

        foreach ($this->batch as $singleJob) {
            $jobId = yield $this->beanstalkClient->put(
                $singleJob->getUuid(),
                $singleJob->getTimeout(),
                $singleJob->getDelay(),
                $singleJob->getPriority()
            );
        }

        $this->batch = [];
    }

    /**
     * @param JobInterface $job
     * @param string $jobBeanstalkId
     */
    private function saveJobBeanstalkId(JobInterface $job, string $jobBeanstalkId): void
    {
        self::$uuidToBeanstalkIdMap[$job->getUuid()] = $jobBeanstalkId;
    }

    /**
     * @param JobInterface $job
     * @return string
     */
    private function getJobBeanstalkId(JobInterface $job): string
    {
        $uuid = $job->getUuid();
        if (array_key_exists($uuid, self::$uuidToBeanstalkIdMap)) {
            return self::$uuidToBeanstalkIdMap[$uuid];
        }

        throw new \RuntimeException("Unknown Beanstalk id for job {$uuid}");
    }
}
