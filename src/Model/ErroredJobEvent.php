<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Model;

final class ErroredJobEvent implements JobEventInterface
{
    public function __construct(
        private readonly \DateTime $time,
        private readonly string $workerFqcn,
        private readonly string $errorMessage,
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

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}
