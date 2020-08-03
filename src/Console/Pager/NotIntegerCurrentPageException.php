<?php

// @codingStandardsIgnoreFile

namespace Webgriffe\Esb\Console\Pager;

trigger_deprecation(
    'webgriffe/esb',
    '2.2',
    'The "%s" exception is deprecated and will be removed in 3.0.',
    NotIntegerCurrentPageException::class
);

/**
 * @deprecated to be removed in 3.0
 */
final class NotIntegerCurrentPageException extends NotValidCurrentPageException
{
}
