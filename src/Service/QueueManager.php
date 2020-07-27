<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Exception\ElasticSearch\JobNotFoundException;
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
            yield $this->beanstalkClient->use($this->flowConfig->getTube());
        });
    }

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
        $jobsCount = 0;
        if ($count >= 1000) { //TODO: should be a parameter
            yield from $this->processBatch($this->batch);
            $jobsCount = $count;
            $this->batch = [];
        }
        return $jobsCount;
    }

    /**
     * @return string
     */
    public function getFlowDescription()
    {
        return $this->flowConfig->getDescription();
    }

    /**
     * @return int
     */
    public function flush()
    {
        $jobsCount = count($this->batch);
        if ($jobsCount > 0) {
            yield from $this->processBatch($this->batch);
            $this->batch = [];
        }
        return $jobsCount;
    }

    /**
     * @return JobInterface
     */
    public function getNextJob()
    {
        $rawJob = yield $this->beanstalkClient->reserve();
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

        return $job;
    }

    public function updateJob(JobInterface $job)
    {
        yield $this->elasticSearch->indexJob($job, $this->flowConfig->getTube());
    }

    public function dequeue(JobInterface $job)
    {
        $jobBeanstalkId = $job->getPayloadData()[''];

        yield $this->beanstalkClient->delete($jobBeanstalkId);
    }

    private function jobExists(string $jobUuid): Promise
    {
        return call(function () use ($jobUuid) {
            try {
                yield $this->elasticSearch->fetchJob($jobUuid, $this->flowConfig->getTube());
            } catch (JobNotFoundException $exception) {
                return false;
            }
            return true;
        });
    }


    /**
     * @param array $batch
     * @return \Generator
     */
    private function processBatch(array $batch): \Generator
    {
        $this->logger->debug('Processing batch');
        yield $this->elasticSearch->bulkIndexJobs($batch, $this->flowConfig->getTube());

        foreach ($batch as $singleJob) {
            $jobId = yield $this->beanstalkClient->put(
                $singleJob->getUuid(),
                $singleJob->getTimeout(),
                $singleJob->getDelay(),
                $singleJob->getPriority()
            );
        }
    }
}
