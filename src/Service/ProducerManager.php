<?php

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\Loop;
use Monolog\Logger;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\ProducerInterface;
use Webgriffe\Esb\RepeatProducerInterface;

class ProducerManager
{
    /**
     * @var BeanstalkClientFactory
     */
    private $beanstalkClientFactory;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var ProducerInterface[]
     */
    private $producers;

    /**
     * ProducerManager constructor.
     * @param BeanstalkClientFactory $beanstalkClientFactory
     * @param Logger $logger
     */
    public function __construct(BeanstalkClientFactory $beanstalkClientFactory, Logger $logger)
    {
        $this->beanstalkClientFactory = $beanstalkClientFactory;
        $this->logger = $logger;
    }

    public function bootProducers()
    {
        if (!count($this->producers)) {
            $this->logger->notice('No producer to start.');
            return;
        }

        foreach ($this->producers as $producer) {
            Loop::defer(function () use ($producer) {
                if ($producer instanceof RepeatProducerInterface) {
                    yield call([$this, 'bootRepeatProducer'], $producer);
                } else {
                    throw new \RuntimeException(sprintf('Unknown producer type "%s".', get_class($producer)));
                }
            });
        }
    }

    public function bootRepeatProducer(RepeatProducerInterface $producer)
    {
        $beanstalkClient = $this->beanstalkClientFactory->create();
        yield call([$producer, 'init']);
        $this->logger->info(
            'A Producer has been successfully initialized',
            ['producer' => \get_class($producer)]
        );
        yield $beanstalkClient->use($producer->getTube());
        Loop::repeat($producer->getInterval(), function ($watcherId) use ($producer, $beanstalkClient) {
            Loop::disable($watcherId);
            $jobs = $producer->produce();
            /** @var Job $job */
            foreach($jobs as $job) {
                try {
                    $payload = serialize($job->getPayloadData());
                    $jobId = yield $beanstalkClient->put($payload);
                    $this->logger->info(
                        'Successfully produced a new Job',
                        [
                            'producer' => \get_class($producer),
                            'job_id' => $jobId,
                            'payload_data' => $job->getPayloadData()
                        ]
                    );
                    $producer->onProduceSuccess($job);
                } catch (\Exception $e) {
                    $this->logger->error(
                        'An error occurred producing a job.',
                        [
                            'producer' => \get_class($producer),
                            'payload_data' => $job->getPayloadData(),
                            'error' => $e->getMessage(),
                        ]
                    );
                    $producer->onProduceFail($job, $e);
                }
            }
            Loop::enable($watcherId);
        });
    }

    public function addProducer(ProducerInterface $producer)
    {
        $this->producers[] = $producer;
    }
}
