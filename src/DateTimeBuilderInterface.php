<?php

namespace Webgriffe\Esb;

interface DateTimeBuilderInterface
{
    public function build($time = 'now', \DateTimeZone $timezone = null): \DateTime;
}
