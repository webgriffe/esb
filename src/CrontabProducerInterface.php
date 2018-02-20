<?php

namespace Webgriffe\Esb;

interface CrontabProducerInterface extends ProducerInterface
{
    public function getCrontab(): string;
}
