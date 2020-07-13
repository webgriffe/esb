<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Loop;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Webgriffe\Esb\Exception\ElasticSearch\JobNotFoundException;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\ProducedJobEvent;
use Webgriffe\Esb\Service\CronProducersServer;
use Webgriffe\Esb\Service\ElasticSearch;
use Webgriffe\Esb\Service\HttpProducersServer;
use function Amp\call;

final class ProducerInstance implements ProducerInstanceInterface
{
    /**
     * @var FlowConfig
     */
    private $flowConfig;
        /**
     * @var ProducerInterface
     */
    private $producer;
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var HttpProducersServer
     */
    private $httpProducersServer;
    /**
     * @var CronProducersServer
     */
    private $cronProducersServer;
    /**
     * @var ElasticSearch
     */
    private $elasticSearch;

    public function __construct(
        FlowConfig $flowConfig,
        ProducerInterface $producer,
        BeanstalkClient $beanstalkClient,
        LoggerInterface $logger,
        HttpProducersServer $httpProducersServer,
        CronProducersServer $cronProducersServer,
        ElasticSearch $elasticSearch
    ) {
        $this->flowConfig = $flowConfig;
        $this->producer = $producer;
        $this->beanstalkClient = $beanstalkClient;
        $this->logger = $logger;
        $this->httpProducersServer = $httpProducersServer;
        $this->cronProducersServer = $cronProducersServer;
        $this->elasticSearch = $elasticSearch;
    }

    public function boot(): Promise
    {
        return call(function () {
            yield $this->producer->init();
            yield $this->beanstalkClient->use($this->flowConfig->getTube());
            $this->logger->info(
                'A Producer has been successfully initialized',
                ['flow' => $this->flowConfig->getDescription(), 'producer' => \get_class($this->producer)]
            );
            if ($this->producer instanceof RepeatProducerInterface) {
                Loop::repeat(
                    $this->producer->getInterval(),
                    function ($watcherId) {
                        Loop::disable($watcherId);
                        yield $this->produceAndQueueJobs();
                        Loop::enable($watcherId);
                    }
                );
            } elseif ($this->producer instanceof  HttpRequestProducerInterface) {
                if (!$this->httpProducersServer->isStarted()) {
                    yield $this->httpProducersServer->start();
                }
                $this->httpProducersServer->addProducerInstance($this);
            } elseif ($this->producer instanceof  CrontabProducerInterface) {
                $this->cronProducersServer->addProducerInstance($this);
                if (!$this->cronProducersServer->isStarted()) {
                    yield $this->cronProducersServer->start();
                }
            } else {
                throw new \RuntimeException(
                    sprintf(
                        'Unknown producer type "%s" for flow "%s".',
                        \get_class($this->producer),
                        $this->flowConfig->getDescription()
                    )
                );
            }
        });
    }

    /**
     * @param mixed $data
     * @return Promise
     */
    public function produceAndQueueJobs($data = null): Promise
    {
        return call(function () use ($data) {
            $jobsCount = 0;
            $job = null;
            $test = false;
            $batch = [];
            try {
                $jobs = $this->producer->produce($data);
                while (yield $jobs->advance()) {
                    /** @var Job $job */
                    $job = $jobs->getCurrent();
                    $job->addEvent(new ProducedJobEvent(new \DateTime(), \get_class($this->producer)));
                    $jobExists = yield $this->jobExists($job->getUuid());
                    if ($jobExists) {
                        throw new \RuntimeException(
                            sprintf(
                                'A job with UUID "%s" already exists but this should be a new job.',
                                $job->getUuid()
                            )
                        );
                    }
                    $batch[] = $job;
                    $jobsCount++; // TODO: Add jobsCount to bulk operations?
                    if (count($batch) >= 1000) { // TODO: 1000 should be a config parameters
                        yield from $this->processBatch($batch);
                        $batch = [];
                    }
                }
                if (count($batch) > 0) {
                    yield from $this->processBatch($batch);
                }
            } catch (\Throwable $error) {
                $this->logger->error(
                    'An error occurred producing/queueing jobs.',
                    [
                        'producer' => \get_class($this->producer),
                        'last_job_payload_data' => $job ? NonUtf8Cleaner::clean($job->getPayloadData()) : null,
                        'error' => $error->getMessage(),
                        'test' => $test
                    ]
                );
            }
            return $jobsCount;
        });
    }

    public function getProducer(): ProducerInterface
    {
        return $this->producer;
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
            $this->logger->info(
                'Successfully produced a new Job',
                [
                    'producer' => \get_class($this->producer),
                    'job_beanstalk_id' => $jobId,
                    'job_uuid' => $singleJob->getUuid(),
                    'payload_data' => NonUtf8Cleaner::clean($singleJob->getPayloadData())
                ]
            );
        }
    }
}
