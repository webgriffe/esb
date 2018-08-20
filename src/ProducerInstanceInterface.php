<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;

interface ProducerInstanceInterface
{
    public function boot(): Promise;
}
