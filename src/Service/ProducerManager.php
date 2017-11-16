<?php

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Loop;
use Webgriffe\Esb\ProducerInterface;
use Webgriffe\Esb\RepeatProducerInterface;

class ProducerManager
{
    /**
     * @var BeanstalkClient
     */
    private $beanstalk;

    /**
     * @var ProducerInterface[]
     */
    private $producers;

    /**
     * ProducerManager constructor.
     * @param BeanstalkClient $beanstalk
     */
    public function __construct(BeanstalkClient $beanstalk)
    {
        $this->beanstalk = $beanstalk;
    }

    public function bootProducers()
    {
        if (!count($this->producers)) {
            printf('No producer to start.' . PHP_EOL);
            return;
        }

        printf('Starting "%s" producers...' . PHP_EOL, count($this->producers));
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
