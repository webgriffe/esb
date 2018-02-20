<?php

namespace Webgriffe\Esb;

class DateTimeBuilder implements DateTimeBuilderInterface
{
    public function build($time = 'now', \DateTimeZone $timezone = null): \DateTime
    {
        return new \DateTime($time, $timezone);
    }
}
