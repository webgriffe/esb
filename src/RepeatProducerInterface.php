<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

interface RepeatProducerInterface extends ProducerInterface
{
    /**
     * @return int
     */
    public function getInterval(): int;
}
