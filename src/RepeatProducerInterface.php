<?php

namespace Webgriffe\Esb;

interface RepeatProducerInterface extends ProducerInterface
{
    /**
     * @return int
     */
    public function getInterval(): int;
}
