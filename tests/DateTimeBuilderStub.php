<?php

namespace Webgriffe\Esb;

class DateTimeBuilderStub implements DateTimeBuilderInterface
{
    public static $forcedNow;

    public function build($time = 'now', \DateTimeZone $timezone = null): \DateTime
    {
        if (self::$forcedNow) {
            $dateTime = new \DateTime(self::$forcedNow, $timezone);
            if (null !== $time) {
                $dateTime->modify($time);
            }
            return $dateTime;
        }
        return new \DateTime($time, $timezone);
    }
}
