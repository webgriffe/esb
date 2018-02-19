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
}
