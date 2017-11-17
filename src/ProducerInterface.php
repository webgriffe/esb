<?php

namespace Webgriffe\Esb;

use Webgriffe\Esb\Model\Job;

interface ProducerInterface
{
    /**
     * @return string
     */
    public function getTube(): string;

    /**
     * @return \Generator|Job[]
     */
    public function produce(): \Generator;

    /**
     * @param Job $job
     * @return void
     */
    public function onProduceSuccess(Job $job);

    /**
     * @param Job $job
     * @param \Exception $exception
     * @return void
     */
    public function onProduceFail(Job $job, \Exception $exception);
}
