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
     * @return void
     */
    public function init();

    /**
     * @param mixed $data
     * @return \Generator|Job[]
     */
    public function produce($data = null): \Generator;

    /**
     * @param Job $job
     * @return void
     */
    public function onProduceSuccess(Job $job);

    /**
     * @param Job $job
     * @param \Throwable $exception
     * @return void
     */
    public function onProduceFail(Job $job, \Throwable $exception);
}
