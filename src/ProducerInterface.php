<?php

namespace Webgriffe\Esb;

use Amp\Promise;
use Webgriffe\Esb\Model\Job;

interface ProducerInterface
{
    /**
     * @return string
     */
    public function getTube(): string;

    /**
     * @return Promise
     */
    public function init(): Promise;

    /**
     * @param mixed $data
     * @return \Generator|Job[]
     */
    public function produce($data = null): \Generator;
}
