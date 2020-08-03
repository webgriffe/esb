<?php

// @codingStandardsIgnoreFile

namespace Webgriffe\Esb\Console\Pager;

trigger_deprecation(
    'webgriffe/esb',
    '2.2',
    'The "%s" exception is deprecated and will be removed in 3.0.',
    NotValidCurrentPageException::class
);

/**
 * @internal
 * @deprecated to be removed in 3.0
 */
class NotValidCurrentPageException extends \InvalidArgumentException
{
}
