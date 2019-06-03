<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

interface DateTimeBuilderInterface
{
    public function build($time = 'now', \DateTimeZone $timezone = null): \DateTime;
}
