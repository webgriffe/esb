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
     * @return \Generator|Job[]
     */
    public function produce(): \Generator
    {
        yield from $this->jobs;
        Loop::stop(); // Stops the loop after first produced jobs
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
     * @param \Exception $exception
     * @return void
     */
    public function onProduceFail(Job $job, \Exception $exception)
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
