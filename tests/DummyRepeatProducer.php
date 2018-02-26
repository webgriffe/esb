<?php

namespace Webgriffe\Esb;

use Amp\Iterator;
use Amp\Promise;
use Amp\Success;

class DummyRepeatProducer implements RepeatProducerInterface
{
    /**
     * @var array
     */
    private $jobs;
    /**
     * @var string
     */
    private $tube;
    /**
     * @var int
     */
    private $interval;

    /**
     * DummyRepeatProducer constructor.
     * @param array $jobs
     * @param string $tube
     * @param int $interval
     */
    public function __construct(array $jobs = [], string $tube, int $interval)
    {
        $this->jobs = $jobs;
        $this->tube = $tube;
        $this->interval = $interval;
    }

    /**
     * @return string
     */
    public function getTube(): string
    {
        return $this->tube;
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
