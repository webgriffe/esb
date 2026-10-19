<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Model;

final class RequeuedJobEvent implements JobEventInterface
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
