<?php

namespace Webgriffe\Esb;

use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Model\Job;

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
     * @param null $data
     * @return \Generator|Job[]
     */
    public function produce($data = null): \Generator
    {
        yield from $this->jobs;
        $this->jobs = []; // We want to produce given jobs only once
    }

    /**
     * @return int
     */
    public function getInterval(): int
    {
        return $this->interval;
    }
}
