<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Model;

final class ProducedJobEvent implements JobEventInterface
{
    public function __construct(
        private readonly \DateTime $time,
        private readonly string $producerFqcn,
    ) {
    }

    public function getTime(): \DateTime
    {
        return $this->time;
    }

    /**
     * @return string
     */
    public function getProducerFqcn(): string
    {
        return $this->producerFqcn;
    }
}
