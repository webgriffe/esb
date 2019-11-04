<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Unit\Model;

use Webgriffe\Esb\Model\JobEventInterface;

class DummyJobEvent implements JobEventInterface
{
    private $time;

    public function __construct(\DateTime $time)
    {
        $this->time = $time;
    }

    public function getTime(): \DateTime
    {
        return $this->time;
    }
}
