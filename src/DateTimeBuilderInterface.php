<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

interface DateTimeBuilderInterface
{
    /**
     * @param string $time
     * @param \DateTimeZone|null $timezone
     * @return \DateTime
     */
    public function build($time = 'now', ?\DateTimeZone $timezone = null): \DateTime;
}
