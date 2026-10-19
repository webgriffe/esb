<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

final class DateTimeBuilder implements DateTimeBuilderInterface
{
    public function build(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime
    {
        return new \DateTime($time, $timezone);
    }
}
