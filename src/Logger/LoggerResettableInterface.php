<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Logger;

use Monolog\ResettableInterface;
use Psr\Log\LoggerInterface;

interface LoggerResettableInterface extends LoggerInterface, ResettableInterface
{
}
