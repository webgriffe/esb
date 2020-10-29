<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

class ProducerResult
{
    /**
     * @var int
     */
    private $jobsCount;

    /**
     * @var \Throwable|null
     */
    private $exception;

    /**
     * @param int $jobsCount
     * @param \Throwable|null $exception
     */
    public function __construct(int $jobsCount, ?\Throwable $exception = null)
    {
        $this->jobsCount = $jobsCount;
        $this->exception = $exception;
    }

    /**
     * @return int
     */
    public function getJobsCount(): int
    {
        return $this->jobsCount;
    }

    /**
     * @return \Throwable|null
     */
    public function getException(): ?\Throwable
    {
        return $this->exception;
    }
}
