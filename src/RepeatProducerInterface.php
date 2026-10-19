<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

interface RepeatProducerInterface extends ProducerInterface
{
    public function getInterval(): int;
}
