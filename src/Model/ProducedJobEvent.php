<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Model;

final class ProducedJobEvent implements JobEventInterface
{
    /**
     * @var \DateTime
     */
    private $time;
    /**
     * @var string
     */
    private $producerFqcn;

    public function __construct(\DateTime $time, string $producerFqcn)
    {
        $this->time = $time;
        $this->producerFqcn = $producerFqcn;
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
