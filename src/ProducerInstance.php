<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Loop;
use Amp\Promise;
use Monolog\Logger;
use Webgriffe\Esb\Model\Job;
use function Amp\call;
use Webgriffe\Esb\Service\CronProducersServer;
use Webgriffe\Esb\Service\HttpProducersServer;

class ProducerInstance
{
    /**
     * @var string
     */
    private $flowName;
    /**
     * @var string
     */
    private $tube;
    /**
     * @var ProducerInterface
     */
    private $producer;
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;
    /**
     * @var Logger
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
        string $flowName,
        string $tube,
        ProducerInterface $producer,
        BeanstalkClient $beanstalkClient,
        Logger $logger,
        HttpProducersServer $httpProducersServer,
        CronProducersServer $cronProducersServer
    ) {
        $this->flowName = $flowName;
        $this->tube = $tube;
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
            yield $this->beanstalkClient->use($this->tube);
            $this->logger->info(
                'A Producer has been successfully initialized',
                ['flow' => $this->flowName, 'producer' => \get_class($this->producer)]
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
                    sprintf('Unknown producer type "%s" for flow "%s".', \get_class($this->producer), $this->flowName)
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
            $jobs = $this->producer->produce($data);

            while (yield $jobs->advance()) {
                /** @var Job $job */
                $job = $jobs->getCurrent();
                $payload = serialize($job->getPayloadData());

                try {
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
                } catch (\Throwable $error) {
                    $this->logger->error(
                        'An error occurred producing a job.',
                        [
                            'producer' => \get_class($this->producer),
                            'payload_data' => NonUtf8Cleaner::clean($job->getPayloadData()),
                            'error' => $error->getMessage(),
                        ]
                    );
                }
            }
            return $jobsCount;
        });
    }

    public function getProducer(): ProducerInterface
    {
        return $this->producer;
    }
}
