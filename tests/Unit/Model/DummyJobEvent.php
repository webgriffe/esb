<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Unit\Model;

use Webgriffe\Esb\Model\JobEventInterface;

class DummyJobEvent implements JobEventInterface
{
    public function __construct(
        private readonly \DateTime $time,
    ) {
    }

    public function getTime(): \DateTime
    {
        return $this->time;
    }
}
