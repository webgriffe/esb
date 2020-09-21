<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Loop;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\ProducedJobEvent;
use Webgriffe\Esb\Service\CronProducersServer;
use Webgriffe\Esb\Service\ElasticSearch;
use Webgriffe\Esb\Service\HttpProducersServer;
use Webgriffe\Esb\Service\ProducerQueueManagerInterface;
use Webgriffe\Esb\Service\QueueManager;
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
     * @var ProducerQueueManagerInterface
     */
    private $queueManager;

    public function __construct(
        FlowConfig $flowConfig,
        ProducerInterface $producer,
        ?BeanstalkClient $beanstalkClient,
        LoggerInterface $logger,
        HttpProducersServer $httpProducersServer,
        CronProducersServer $cronProducersServer,
        ?ElasticSearch $elasticSearch,
        ProducerQueueManagerInterface $queueManager = null
    ) {
        if ($beanstalkClient !== null) {
            trigger_deprecation(
                'webgriffe/esb',
                '2.2',
                'Passing a "%s" to "%s" is deprecated and will be removed in 3.0. ' .
                'Please pass a "%s" instead.',
                BeanstalkClient::class,
                __CLASS__,
                ProducerQueueManagerInterface::class
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
                ProducerQueueManagerInterface::class
            );
        }
        $this->flowConfig = $flowConfig;
        $this->producer = $producer;
        $this->logger = $logger;
        $this->httpProducersServer = $httpProducersServer;
        $this->cronProducersServer = $cronProducersServer;

        if ($queueManager === null) {
            trigger_deprecation(
                'webgriffe/esb',
                '2.2',
                'Not passing a "%s" to "%s" is deprecated and will be required in 3.0.',
                ProducerQueueManagerInterface::class,
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
            yield $this->producer->init();
            yield $this->queueManager->boot();

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
     * @return Promise<null>
     */
    public function produceAndQueueJobs($data = null): Promise
    {
        return call(function () use ($data) {
            $jobsCount = 0;
            $job = null;
            $test = false;
            try {
                $jobs = $this->producer->produce($data);
                while (yield $jobs->advance()) {
                    /** @var Job $job */
                    $job = $jobs->getCurrent();
                    $job->addEvent(new ProducedJobEvent(new \DateTime(), \get_class($this->producer)));
                    $jobsCount += yield $this->queueManager->enqueue($job);
                    $this->logger->info(
                        'Successfully produced a new Job',
                        [
                            'producer' => \get_class($this->producer),
                            'job_uuid' => $job->getUuid(),
                            'payload_data' => NonUtf8Cleaner::clean($job->getPayloadData())
                        ]
                    );
                }

                $jobsCount += yield $this->queueManager->flush();
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
}
