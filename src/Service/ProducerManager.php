<?php

namespace Webgriffe\Esb\Service;

use Amp\Loop;
use Webgriffe\Esb\Service\Producer\ProducerInterface;

class ProducerManager
{
    /**
     * @var ProducerInterface[]
     */
    private $producers;

    public function bootProducers()
    {
        if (!count($this->producers)) {
            printf('No producer to start.' . PHP_EOL);
            return;
        }

        printf('Starting "%s" producers...' . PHP_EOL, count($this->producers));
        foreach ($this->producers as $producer) {
            Loop::defer([$producer, 'produce']);
        }
    }

    public function addProducer(ProducerInterface $producer)
    {
        $this->producers[] = $producer;
    }
}
