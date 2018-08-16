<?php

namespace Webgriffe\Esb;

use Amp\Iterator;
use Amp\Promise;
use Amp\Success;

class DummyCrontabProducer implements CrontabProducerInterface
{
    /**
     * @var array
     */
    private $jobs;

    public function getCrontab(): string
    {
        return '0 * * * *'; // Runs every hour at minute 0
    }

    /**
     * @return Promise
     * @throws \Error
     */
    public function init(): Promise
    {
        return new Success(null);
    }

    /**
     * @param mixed $data
     * @return Iterator
     * @throws \TypeError
     */
    public function produce($data = null): Iterator
    {
        $iterator = Iterator\fromIterable($this->jobs);
        $this->jobs = [];
        return $iterator;
    }

    /**
     * @param array $jobs
     */
    public function setJobs(array $jobs)
    {
        $this->jobs = $jobs;
    }
}
