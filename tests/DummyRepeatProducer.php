<?php

namespace Webgriffe\Esb;

use Amp\Promise;
use Amp\Success;
use Amp\Iterator;

class DummyRepeatProducer implements RepeatProducerInterface
{
    /**
     * @var array
     */
    private $jobs;
    /**
     * @var int
     */
    private $interval;

    /**
     * DummyRepeatProducer constructor.
     * @param array $jobs
     * @param int $interval
     */
    public function __construct(array $jobs, int $interval)
    {
        $this->jobs = $jobs;
        $this->interval = $interval;
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
     * @return int
     */
    public function getInterval(): int
    {
        return $this->interval;
    }
}
