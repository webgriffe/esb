<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\delay;
use Amp\Loop;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Service\CronProducersServer;
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

    public function __construct(
        FlowConfig $flowConfig,
        ProducerInterface $producer,
        BeanstalkClient $beanstalkClient,
        LoggerInterface $logger,
        HttpProducersServer $httpProducersServer,
        CronProducersServer $cronProducersServer
    ) {
        $this->flowConfig = $flowConfig;
        $this->producer = $producer;
        $this->beanstalkClient = $beanstalkClient;
        $this->logger = $logger;
        $this->httpProducersServer = $httpProducersServer;
        $this->cronProducersServer = $cronProducersServer;
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
                do {
                    yield $this->produceAndQueueJobs();
                    yield delay($this->producer->getInterval());

                    $info = Loop::getInfo();
                } while ($info['running']);
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
            try {
                $jobs = $this->producer->produce($data);
                while (yield $jobs->advance()) {
                    /** @var Job $job */
                    $job = $jobs->getCurrent();
                    $payload = serialize($job->getPayloadData());
                    $jobId = yield $this->beanstalkClient->put(
                        $payload,
                        $job->getTimeout(),
                        $job->getDelay(),
                        $job->getPriority()
                    );
                    $this->logger->info(
                        'Successfully produced a new Job',
                        [
                            'producer' => \get_class($this->producer),
                            'job_id' => $jobId,
                            'payload_data' => NonUtf8Cleaner::clean($job->getPayloadData())
                        ]
                    );
                    $jobsCount++;
                }
            } catch (\Throwable $error) {
                $this->logger->error(
                    'An error occurred producing/queueing jobs.',
                    [
                        'producer' => \get_class($this->producer),
                        'last_job_payload_data' => $job ? NonUtf8Cleaner::clean($job->getPayloadData()) : null,
                        'error' => $error->getMessage(),
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
