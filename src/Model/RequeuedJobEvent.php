<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Model;

class RequeuedJobEvent implements JobEventInterface
{
    /**
     * @var \DateTime
     */
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
