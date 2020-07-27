<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Loop;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\ProducedJobEvent;
use Webgriffe\Esb\Service\CronProducersServer;
use Webgriffe\Esb\Service\HttpProducersServer;
use Webgriffe\Esb\Service\QueueManager;
use function Amp\call;

final class ProducerInstance implements ProducerInstanceInterface
{
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
     * @var QueueManager
     */
    private $queueManager;

    public function __construct(
        ProducerInterface $producer,
        LoggerInterface $logger,
        HttpProducersServer $httpProducersServer,
        CronProducersServer $cronProducersServer,
        QueueManager $queueManager
    ) {
        $this->producer = $producer;
        $this->logger = $logger;
        $this->httpProducersServer = $httpProducersServer;
        $this->cronProducersServer = $cronProducersServer;
        $this->queueManager = $queueManager;
    }

    public function boot(): Promise
    {
        return call(function () {
            yield $this->producer->init();
            yield $this->queueManager->boot();
            $this->logger->info(
                'A Producer has been successfully initialized',
                ['flow' => $this->queueManager->getFlowDescription(), 'producer' => \get_class($this->producer)]
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
                        $this->queueManager->getFlowDescription()
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
            try {
                $jobs = $this->producer->produce($data);
                while (yield $jobs->advance()) {
                    /** @var Job $job */
                    $job = $jobs->getCurrent();
                    $job->addEvent(new ProducedJobEvent(new \DateTime(), \get_class($this->producer)));
                    $jobsCount += yield from $this->queueManager->enqueue($job);
                    $this->logger->info(
                        'Successfully produced a new Job',
                        [
                            'producer' => \get_class($this->producer),
                            'job_uuid' => $job->getUuid(),
                            'payload_data' => NonUtf8Cleaner::clean($job->getPayloadData())
                        ]
                    );
                }

                $jobsCount += yield from $this->queueManager->flush();
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
