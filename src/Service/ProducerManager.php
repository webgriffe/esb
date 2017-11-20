<?php

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Loop;
use Monolog\Logger;
use Webgriffe\Esb\ProducerInterface;
use Webgriffe\Esb\RepeatProducerInterface;

class ProducerManager
{
    /**
     * @var BeanstalkClient
     */
    private $beanstalk;

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
     * @param BeanstalkClient $beanstalk
     * @param Logger $logger
     */
    public function __construct(BeanstalkClient $beanstalk, Logger $logger)
    {
        $this->beanstalk = $beanstalk;
        $this->logger = $logger;
    }

    public function bootProducers()
    {
        if (!count($this->producers)) {
            $this->logger->notice('No producer to start.');
            return;
        }

        $this->logger->info(sprintf('Starting "%s" producers...', count($this->producers)));
        foreach ($this->producers as $producer) {
            Loop::defer(function () use ($producer) {
                if ($producer instanceof RepeatProducerInterface) {
                    yield $this->beanstalk->use($producer->getTube());
                    Loop::repeat($producer->getInterval(), function () use ($producer) {
                        $jobs = $producer->produce();
                        foreach ($jobs as $job) {
                            try {
                                $payload = serialize($job->getPayloadData());
                                yield $this->beanstalk->put($payload);
                                $producer->onProduceSuccess($job);
                            } catch (\Exception $e) {
                                $this
                                    ->logger
                                    ->error(
                                        'An error occurred producing a job.',
                                        [
                                            'producer' => get_class($producer),
                                            'error' => $e->getMessage(),
                                            'payload_data' => $job->getPayloadData()
                                        ]
                                    );
                                $producer->onProduceFail($job, $e);
                            }
                        }
                    });
                } else {
                    throw new \RuntimeException(sprintf('Unknown producer type "%s".', get_class($producer)));
                }
            });
        }
    }

    public function addProducer(ProducerInterface $producer)
    {
        $this->producers[] = $producer;
    }
}
