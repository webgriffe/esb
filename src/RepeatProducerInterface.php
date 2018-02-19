<?php

namespace Webgriffe\Esb;

use Webgriffe\Esb\Model\Job;

interface RepeatProducerInterface extends ProducerInterface
{
    /**
     * @return int
     */
    public function getInterval(): int;
}
