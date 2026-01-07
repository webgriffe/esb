<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use function Amp\call;
use Amp\Loop;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\ProducedJobEvent;
use Webgriffe\Esb\Service\BatchManagerFactory;
use Webgriffe\Esb\Service\CronProducersServer;

use Webgriffe\Esb\Service\HttpProducersServer;

use Webgriffe\Esb\Service\QueueBackendInterface;

final class ProducerInstance implements ProducerInstanceInterface
{
    public function __construct(
        private readonly FlowConfig $flowConfig,
        private readonly ProducerInterface $producer,
        private readonly LoggerInterface $logger,
        private readonly HttpProducersServer $httpProducersServer,
        private readonly CronProducersServer $cronProducersServer,
        private readonly QueueBackendInterface $queueBackend,
        private readonly BatchManagerFactory $batchManagerFactory,
    ) {
    }

    public function boot(): Promise
    {
        return call(function () {
            yield $this->producer->init();
            yield $this->queueBackend->boot();

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
            } elseif ($this->producer instanceof HttpRequestProducerInterface) {
                if (!$this->httpProducersServer->isStarted()) {
                    yield $this->httpProducersServer->start();
                }
                $this->httpProducersServer->addProducerInstance($this);
            } elseif ($this->producer instanceof CrontabProducerInterface) {
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
            $batchManager = $this->batchManagerFactory->create();
            $jobsCount = 0;
            $job = null;
            try {
                $jobs = $this->producer->produce($data);
                while (yield $jobs->advance()) {
                    /** @var Job $job */
                    $job = $jobs->getCurrent();
                    $job->addEvent(new ProducedJobEvent(new \DateTime(), \get_class($this->producer)));
                    $jobsCount += yield $batchManager->enqueue($job);
                }

                $jobsCount += yield $batchManager->flush();
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
