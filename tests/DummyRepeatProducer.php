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
     * DummyRepeatProducer constructor.
     * @param array $jobs
     */
    public function __construct(array $jobs = [])
    {
        $this->jobs = $jobs;
    }

    /**
     * @return string
     */
    public function getTube(): string
    {
        return 'test';
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
        return 1;
    }
}
