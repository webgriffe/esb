<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Model;

final class ReservedJobEvent implements JobEventInterface
{
    public function __construct(
        private readonly \DateTime $time,
        private readonly string $workerFqcn,
    ) {
    }

    public function getTime(): \DateTime
    {
        return $this->time;
    }

    public function getWorkerFqcn(): string
    {
        return $this->workerFqcn;
    }
}
