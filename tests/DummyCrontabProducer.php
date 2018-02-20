<?php

namespace Webgriffe\Esb;

use Webgriffe\Esb\Model\Job;

class DummyCrontabProducer implements CrontabProducerInterface
{
    /**
     * @var array
     */
    private $jobs;
    /**
     * @var string
     */
    private $tube;

    public function __construct(string $tube)
    {
        $this->tube = $tube;
    }

    public function getCrontab(): string
    {
        return '0 * * * *'; // Runs every hour at minute 0
    }

    /**
     * @return string
     */
    public function getTube(): string
    {
        return $this->tube;
    }

    /**
     * @return void
     */
    public function init()
    {
    }

    /**
     * @param mixed $data
     * @return \Generator|Job[]
     */
    public function produce($data = null): \Generator
    {
        yield from $this->jobs;
        $this->jobs = []; // We want to produce given jobs only once
    }

    /**
     * @param array $jobs
     */
    public function setJobs(array $jobs)
    {
        $this->jobs = $jobs;
    }
}
