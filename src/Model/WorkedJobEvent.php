<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Model;

class WorkedJobEvent implements JobEventInterface
{
    /**
     * @var \DateTime
     */
    private $time;
    /**
     * @var string
     */
    private $workerFqcn;

    public function __construct(\DateTime $time, string $workerFqcn)
    {
        $this->time = $time;
        $this->workerFqcn = $workerFqcn;
    }

    public function getTime(): \DateTime
    {
        return $this->time;
    }

    public function getWorkerFqcn()
    {
        return $this->workerFqcn;
    }
}
