<?php

namespace Webgriffe\Esb;

use Amp\Loop;
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
     * @return void
     */
    public function init()
    {
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
     * @param Job $job
     * @return void
     */
    public function onProduceSuccess(Job $job)
    {
    }

    /**
     * @param Job $job
     * @param \Throwable $exception
     * @return void
     */
    public function onProduceFail(Job $job, \Throwable $exception)
    {
    }

    /**
     * @return int
     */
    public function getInterval(): int
    {
        return $this->interval;
    }
}
