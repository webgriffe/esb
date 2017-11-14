<?php

namespace Webgriffe\Esb\Service\Producer;

interface RepeatProducerInterface extends ProducerInterface
{
    /**
     * @return int
     */
    public function getInterval(): int;
}
