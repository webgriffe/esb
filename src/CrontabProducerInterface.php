<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

interface CrontabProducerInterface extends ProducerInterface
{
    /**
     * @return string
     */
    public function getCrontab(): string;
}
