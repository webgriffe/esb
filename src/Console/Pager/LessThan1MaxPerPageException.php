<?php

// @codingStandardsIgnoreFile

namespace Webgriffe\Esb\Console\Pager;

trigger_deprecation(
    'webgriffe/esb',
    '2.2',
    'The "%s" class is deprecated and will be removed in 3.0. Use the "%s" class instead.',
    LessThan1MaxPerPageException::class,
    \Pagerfanta\Exception\LessThan1MaxPerPageException::class
);

final class LessThan1MaxPerPageException extends NotValidMaxPerPageException
{
}
